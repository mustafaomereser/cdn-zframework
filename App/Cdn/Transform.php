<?php

namespace App\Cdn;

use App\Models\Cdn\Variants;

/**
 * On-the-fly image derivatives.
 *
 * The URL carries what is wanted (?w=400&fit=cover&format=webp), the parameters
 * are normalised into a canonical form, and that form is hashed into a
 * signature. The signature is the filename of the result, so the same request
 * from anyone is one lookup and the work happens exactly once per distinct
 * derivative.
 *
 * The bucket's cache_version goes into the signature too: bumping it changes
 * every signature at once, which is how a purge invalidates a million
 * derivatives without touching a million files.
 *
 * The parameters come from a stranger, so every one of them is clamped. An
 * unclamped resize is a memory allocation an attacker chooses.
 */
class Transform
{
    /**
     * Parameters that mean "transform this".
     */
    private const KEYS = ['w', 'h', 'fit', 'q', 'format', 'p', 'dpr', 'blur', 'gray', 'rotate', 'flip', 'crop', 'bg', 'sharpen'];

    /**
     * Is the request asking for one?
     *
     * @return bool
     */
    public static function requested(): bool
    {
        foreach (self::KEYS as $key) if (isset($_GET[$key])) return true;
        return false;
    }

    /**
     * Normalise query parameters into the canonical set, or null when there is
     * nothing to do.
     *
     * Canonical matters: ?w=400&h=0 and ?w=400 have to produce the same
     * signature, or the cache fills with duplicates of one image.
     *
     * @param array $query
     * @param array $file
     * @param array $bucket
     * @return array|null
     */
    public static function parse(array $query, array $file, array $bucket): ?array
    {
        if (!Support::config('transform.enabled', true)) return null;
        if (empty($bucket['transform'])) return null;
        if (!Support::isTransformable($file['mime'] ?? null)) return null;

        # A preset is a stored set of parameters; anything explicit in the URL
        # still wins, so ?p=thumb&format=webp is a thumbnail as webp.
        $params = [];
        if (!empty($query['p'])) {
            $presets = (array) Support::config('transform.presets', []);
            $name    = (string) $query['p'];
            if (!isset($presets[$name])) return null;
            $params = (array) $presets[$name];
        } elseif (Support::config('transform.allowed-presets-only', false)) {
            # Only named presets are servable, so a bare ?w=413 is not a
            # transform at all - the original is returned.
            return null;
        }

        foreach (self::KEYS as $key) if (isset($query[$key]) && $key !== 'p') $params[$key] = $query[$key];

        $maxWidth  = (int) Support::config('transform.max-width', 5000);
        $maxHeight = (int) Support::config('transform.max-height', 5000);

        # Device pixel ratio multiplies the requested size, so it is clamped
        # before it is applied rather than after.
        $dpr = isset($params['dpr']) ? (float) $params['dpr'] : 1.0;
        $dpr = max(1.0, min(4.0, $dpr));

        $canonical = [];

        $width  = isset($params['w']) ? (int) round(((float) $params['w']) * $dpr) : 0;
        $height = isset($params['h']) ? (int) round(((float) $params['h']) * $dpr) : 0;

        if ($width > 0)  $canonical['w'] = min($width,  $maxWidth);
        if ($height > 0) $canonical['h'] = min($height, $maxHeight);

        if (isset($params['fit'])) {
            $fit = strtolower((string) $params['fit']);
            if (in_array($fit, ['cover', 'contain', 'fill', 'pad'], true)) $canonical['fit'] = $fit;
        }

        if (isset($params['q'])) {
            $quality = (int) $params['q'];
            if ($quality >= 1 && $quality <= 100) $canonical['q'] = $quality;
        }

        if (isset($params['crop'])) {
            $crop = array_map('intval', explode(',', (string) $params['crop']));
            if (count($crop) === 4 && $crop[2] > 0 && $crop[3] > 0 && $crop[0] >= 0 && $crop[1] >= 0) $canonical['crop'] = implode(',', $crop);
        }

        if (isset($params['rotate'])) {
            $rotate = ((int) $params['rotate'] % 360 + 360) % 360;
            if (in_array($rotate, [90, 180, 270], true)) $canonical['rotate'] = $rotate;
        }

        if (isset($params['flip'])) {
            $flip = strtolower((string) $params['flip']);
            if (in_array($flip, ['h', 'v', 'both'], true)) $canonical['flip'] = $flip;
        }

        if (isset($params['blur'])) {
            $blur = (int) $params['blur'];
            if ($blur > 0) $canonical['blur'] = min(100, $blur);
        }

        if (isset($params['sharpen']) && (int) $params['sharpen'] > 0) $canonical['sharpen'] = min(100, (int) $params['sharpen']);

        if (!empty($params['gray'])) $canonical['gray'] = 1;

        if (isset($params['bg'])) {
            $background = ltrim(strtolower((string) $params['bg']), '#');
            if (preg_match('/^[0-9a-f]{6}$/', $background)) $canonical['bg'] = $background;
        }

        $format = self::resolveFormat($params['format'] ?? null, $file);
        if ($format !== null) $canonical['format'] = $format;

        if (!count($canonical)) return null;

        # Nothing but a format that matches what is already stored: serving the
        # original is the same bytes without the work.
        if (count($canonical) === 1 && isset($canonical['format']) && $canonical['format'] === self::formatOf($file['mime'] ?? '')) return null;

        ksort($canonical);

        return $canonical;
    }

    /**
     * Which output format to encode.
     *
     * 'auto' - and an absent format when auto-format is on - looks at Accept.
     * That makes the response vary by a request header, so Delivery attaches
     * `Vary: Accept`; a cache in front that ignores it will hand somebody an
     * avif their browser cannot read.
     *
     * @param mixed $requested
     * @param array $file
     * @return string|null
     */
    private static function resolveFormat(mixed $requested, array $file): ?string
    {
        $allowed = array_map('strtolower', (array) Support::config('transform.formats', []));
        $format  = $requested !== null ? strtolower((string) $requested) : null;

        if ($format !== null && $format !== 'auto') {
            if ($format === 'jpeg') $format = 'jpg';
            return in_array($format, $allowed, true) && self::supports($format) ? $format : null;
        }

        $auto = $format === 'auto' || Support::config('transform.auto-format', true);
        if (!$auto) return null;

        $accepts = Support::accepts();
        $current = self::formatOf($file['mime'] ?? '');

        # Only ever upgrade. Re-encoding a png as avif is a win; re-encoding an
        # animated gif into a still webp is a bug.
        if ($current === 'gif') return null;

        foreach (['avif', 'webp'] as $candidate) {
            if (!in_array($candidate, $allowed, true) || !self::supports($candidate)) continue;
            if (in_array("image/$candidate", $accepts, true)) return $candidate === $current ? null : $candidate;
        }

        return null;
    }

    /**
     * @param string $mime
     * @return string
     */
    private static function formatOf(string $mime): string
    {
        return match (strtolower(strtok($mime, ';'))) {
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/gif'  => 'gif',
            'image/webp' => 'webp',
            'image/avif' => 'avif',
            'image/bmp'  => 'bmp',
            default      => '',
        };
    }

    /**
     * @param string $format
     * @return string
     */
    public static function mimeOf(string $format): string
    {
        return match ($format) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'gif'  => 'image/gif',
            'webp' => 'image/webp',
            'avif' => 'image/avif',
            'bmp'  => 'image/bmp',
            default => 'application/octet-stream',
        };
    }

    /**
     * Can this build actually write that format?
     *
     * A php without avif support would otherwise answer an ?format=avif with a
     * fatal instead of the original.
     *
     * @param string $format
     * @return bool
     */
    public static function supports(string $format): bool
    {
        if (self::driver() === 'imagick') {
            static $formats = null;
            $formats ??= array_map('strtolower', \Imagick::queryFormats());
            return in_array($format === 'jpg' ? 'jpeg' : $format, $formats, true);
        }

        return match ($format) {
            'jpg', 'jpeg' => function_exists('imagejpeg'),
            'png'  => function_exists('imagepng'),
            'gif'  => function_exists('imagegif'),
            'webp' => function_exists('imagewebp'),
            'avif' => function_exists('imageavif'),
            'bmp'  => function_exists('imagebmp'),
            default => false,
        };
    }

    /**
     * Which image library is in use.
     *
     * @return string gd | imagick | none
     */
    public static function driver(): string
    {
        static $driver = null;
        if ($driver !== null) return $driver;

        $configured = strtolower((string) Support::config('transform.driver', 'auto'));

        if ($configured === 'imagick') return $driver = extension_loaded('imagick') ? 'imagick' : 'none';
        if ($configured === 'gd')      return $driver = extension_loaded('gd') ? 'gd' : 'none';

        # auto: Imagick resamples better and handles more formats, so it wins
        # when both are present.
        if (extension_loaded('imagick')) return $driver = 'imagick';
        if (extension_loaded('gd'))      return $driver = 'gd';

        return $driver = 'none';
    }

    /**
     * The cache key for a derivative.
     *
     * @param array $file
     * @param array $bucket
     * @param array $params
     * @return string
     */
    public static function signature(array $file, array $bucket, array $params): string
    {
        return sha1(implode('|', [
            $file['id'] ?? 0,
            $file['hash'] ?? '',
            (int) ($bucket['cache_version'] ?? 1),
            json_encode($params),
        ]));
    }

    /**
     * Find or build the derivative.
     *
     * Returns null when it cannot be produced - no image library, an unreadable
     * source, a format this build cannot write. The caller then serves the
     * original, which is the right failure: a slightly larger image beats a
     * broken one.
     *
     * @param array $file
     * @param array $bucket
     * @param array $params
     * @return array|null  ['row' => variant row, 'built' => bool]
     */
    public static function resolve(array $file, array $bucket, array $params): ?array
    {
        if (self::driver() === 'none') return null;

        $signature = self::signature($file, $bucket, $params);
        $model     = new Variants;

        $row = $model->where('signature', $signature)->where('file_id', $file['id'])->closureMode(false)->first();

        if ($row && Storage::variantAbsolute($row['storage_path']) && is_file(Storage::variantAbsolute($row['storage_path'])))
            return ['row' => $row, 'built' => false];

        # The row exists but the file behind it does not - a cleared variant
        # directory, a half-finished eviction. Rebuild rather than 404.
        $built = self::build($file, $bucket, $params, $signature);
        if ($built === null) return null;

        if ($row) {
            $model->where('id', $row['id'])->update($built);
            $row = $built + $row;
        } else {
            $row = $model->insert([
                'file_id'   => $file['id'],
                'bucket_id' => $file['bucket_id'],
                'signature' => $signature,
            ] + $built);
        }

        return ['row' => $row, 'built' => true];
    }

    /**
     * Produce the bytes and store them.
     *
     * @param array  $file
     * @param array  $bucket
     * @param array  $params
     * @param string $signature
     * @return array|null  Columns describing the result.
     */
    private static function build(array $file, array $bucket, array $params, string $signature): ?array
    {
        $source = Storage::absolute($file['storage_path'], $file['disk'] ?? null);
        if (!is_file($source)) return null;

        $size = @getimagesize($source);
        if (!$size) return null;

        # Guard on the source, before anything is decoded: a 30000x30000 png is
        # a few hundred kilobytes on disk and several gigabytes in memory.
        $maxPixels = (int) Support::config('transform.max-pixels', 25000000);
        if ($maxPixels > 0 && ($size[0] * $size[1]) > $maxPixels) return null;

        $format  = $params['format'] ?? (self::formatOf($file['mime']) ?: 'jpg');
        $quality = (int) ($params['q'] ?? Support::config('transform.quality', 82));

        if (!self::supports($format)) return null;

        $started = hrtime(true);

        try {
            $result = self::driver() === 'imagick'
                ? self::withImagick($source, $params, $format, $quality)
                : self::withGd($source, $params, $format, $quality);
        } catch (\Throwable $e) {
            # A broken image is not an application error - it is one file out of
            # many. Logged, and the original gets served.
            if (function_exists('errorHandler') && Support::config('transform.log-failures', false)) errorHandler($e);
            return null;
        }

        if ($result === null) return null;

        $relative = Storage::variantPath($signature, $format === 'jpg' ? 'jpg' : $format);
        if (!Storage::writeVariant($relative, $result['bytes'])) return null;

        return [
            'params'       => json_encode($params),
            'format'       => $format,
            'mime'         => self::mimeOf($format),
            'width'        => $result['width'],
            'height'       => $result['height'],
            'size'         => strlen($result['bytes']),
            'storage_path' => $relative,
            'etag'         => '"' . substr($signature, 0, 32) . '"',
            'build_ms'     => (int) round((hrtime(true) - $started) / 1e6),
        ];
    }

    /**
     * Work out what to read from the source and what to draw.
     *
     * Expressed as a source rectangle plus a destination size, rather than as a
     * scale factor, because that is the only formulation in which `cover` is
     * correct: cover means "crop to the requested aspect ratio, then scale", and
     * a single scale factor cannot say which part of the source to keep.
     *
     * @param int   $sourceWidth
     * @param int   $sourceHeight
     * @param array $params
     * @return array{
     *   src_x:int, src_y:int, src_w:int, src_h:int,
     *   dst_w:int, dst_h:int,
     *   canvas_w:int, canvas_h:int,
     *   offset_x:int, offset_y:int
     * }
     */
    private static function geometry(int $sourceWidth, int $sourceHeight, array $params): array
    {
        $width  = (int) ($params['w'] ?? 0);
        $height = (int) ($params['h'] ?? 0);
        $fit    = $params['fit'] ?? 'contain';

        # Only one dimension given: the other follows the aspect ratio, whatever
        # the fit mode says. "Width 400" has one obvious meaning.
        if ($width && !$height)  $height = max(1, (int) round($sourceHeight * ($width / $sourceWidth)));
        if ($height && !$width)  $width  = max(1, (int) round($sourceWidth * ($height / $sourceHeight)));
        if (!$width && !$height) { $width = $sourceWidth; $height = $sourceHeight; }

        # The whole source, unless cover decides otherwise.
        $source = ['src_x' => 0, 'src_y' => 0, 'src_w' => $sourceWidth, 'src_h' => $sourceHeight];

        if ($fit === 'fill') {
            # Stretch to exactly what was asked for. Distortion is the explicit
            # request here, so the no-upscale rule does not apply - "fill" with
            # a box bigger than the image can only mean enlarge it.
            return $source + ['dst_w' => $width, 'dst_h' => $height, 'canvas_w' => $width, 'canvas_h' => $height, 'offset_x' => 0, 'offset_y' => 0];
        }

        if ($fit === 'cover') {
            # The largest region of the source carrying the requested aspect
            # ratio, centred. Then the output is that region scaled down - never
            # up, so asking for 2000x2000 of a 300x200 image gives 200x200 of it
            # rather than a blurry 2000x2000.
            $targetRatio = $width / $height;
            $sourceRatio = $sourceWidth / $sourceHeight;

            if ($sourceRatio > $targetRatio) {
                $regionHeight = $sourceHeight;
                $regionWidth  = (int) round($sourceHeight * $targetRatio);
            } else {
                $regionWidth  = $sourceWidth;
                $regionHeight = (int) round($sourceWidth / $targetRatio);
            }

            $regionWidth  = max(1, min($regionWidth, $sourceWidth));
            $regionHeight = max(1, min($regionHeight, $sourceHeight));

            $outputWidth  = max(1, min($width, $regionWidth));
            $outputHeight = max(1, (int) round($outputWidth / $targetRatio));

            return [
                'src_x' => (int) round(($sourceWidth - $regionWidth) / 2),
                'src_y' => (int) round(($sourceHeight - $regionHeight) / 2),
                'src_w' => $regionWidth,
                'src_h' => $regionHeight,
                'dst_w' => $outputWidth,
                'dst_h' => $outputHeight,
                'canvas_w' => $outputWidth,
                'canvas_h' => $outputHeight,
                'offset_x' => 0,
                'offset_y' => 0,
            ];
        }

        # contain and pad: fit inside the box, keeping the aspect ratio, never
        # enlarging.
        $scale = min($width / $sourceWidth, $height / $sourceHeight, 1.0);

        $destinationWidth  = max(1, (int) round($sourceWidth * $scale));
        $destinationHeight = max(1, (int) round($sourceHeight * $scale));

        if ($fit === 'pad') {
            # The canvas stays the requested size: padding adds background, not
            # enlarged pixels, so the no-upscale rule has nothing to say about it.
            return $source + [
                'dst_w' => $destinationWidth, 'dst_h' => $destinationHeight,
                'canvas_w' => $width, 'canvas_h' => $height,
                'offset_x' => (int) round(($width - $destinationWidth) / 2),
                'offset_y' => (int) round(($height - $destinationHeight) / 2),
            ];
        }

        return $source + [
            'dst_w' => $destinationWidth, 'dst_h' => $destinationHeight,
            'canvas_w' => $destinationWidth, 'canvas_h' => $destinationHeight,
            'offset_x' => 0, 'offset_y' => 0,
        ];
    }

    /**
     * GD pipeline.
     *
     * @param string $source
     * @param array  $params
     * @param string $format
     * @param int    $quality
     * @return array|null
     */
    private static function withGd(string $source, array $params, string $format, int $quality): ?array
    {
        $image = @imagecreatefromstring((string) file_get_contents($source));
        if (!$image) return null;

        try {
            if (isset($params['crop'])) {
                [$x, $y, $w, $h] = array_map('intval', explode(',', $params['crop']));
                $cropped = @imagecrop($image, ['x' => $x, 'y' => $y, 'width' => $w, 'height' => $h]);
                if ($cropped) {
                    imagedestroy($image);
                    $image = $cropped;
                }
            }

            $geometry = self::geometry(imagesx($image), imagesy($image), $params);

            $canvas = imagecreatetruecolor($geometry['canvas_w'], $geometry['canvas_h']);

            # Transparency has to be arranged before anything is drawn. Without
            # this a png with an alpha channel comes out on a black rectangle.
            if (in_array($format, ['png', 'webp', 'avif', 'gif'], true) && !isset($params['bg'])) {
                imagealphablending($canvas, false);
                imagesavealpha($canvas, true);
                imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
            } else {
                $background = isset($params['bg'])
                    ? sscanf($params['bg'], '%2x%2x%2x')
                    : [255, 255, 255];
                imagefill($canvas, 0, 0, imagecolorallocate($canvas, $background[0], $background[1], $background[2]));
            }

            # One resample from the source rectangle onto the canvas. Cover's
            # crop is expressed as the source rectangle rather than as a second
            # pass over the result, so the pixels are only ever interpolated
            # once - a crop after a resize costs quality for nothing.
            imagecopyresampled(
                $canvas,
                $image,
                $geometry['offset_x'],
                $geometry['offset_y'],
                $geometry['src_x'],
                $geometry['src_y'],
                $geometry['dst_w'],
                $geometry['dst_h'],
                $geometry['src_w'],
                $geometry['src_h']
            );

            imagedestroy($image);

            if (isset($params['rotate'])) {
                # GD rotates counter-clockwise; the URL means the clockwise angle
                # everyone else means.
                $rotated = imagerotate($canvas, 360 - (int) $params['rotate'], 0);
                if ($rotated) {
                    imagedestroy($canvas);
                    $canvas = $rotated;
                    imagesavealpha($canvas, true);
                }
            }

            if (isset($params['flip'])) {
                imageflip($canvas, match ($params['flip']) {
                    'h'    => IMG_FLIP_HORIZONTAL,
                    'v'    => IMG_FLIP_VERTICAL,
                    default => IMG_FLIP_BOTH,
                });
            }

            if (!empty($params['gray'])) imagefilter($canvas, IMG_FILTER_GRAYSCALE);

            if (!empty($params['blur'])) {
                # GD has no radius parameter, so the strength is repetitions.
                # Capped: each pass is a full convolution over every pixel.
                $passes = max(1, min(12, (int) round($params['blur'] / 8)));
                for ($pass = 0; $pass < $passes; $pass++) imagefilter($canvas, IMG_FILTER_GAUSSIAN_BLUR);
            }

            if (!empty($params['sharpen'])) {
                $amount = min(1.0, $params['sharpen'] / 100);
                $matrix = [[0, -$amount, 0], [-$amount, 1 + 4 * $amount, -$amount], [0, -$amount, 0]];
                @imageconvolution($canvas, $matrix, 1, 0);
            }

            $width  = imagesx($canvas);
            $height = imagesy($canvas);

            ob_start();
            match ($format) {
                'jpg', 'jpeg' => imagejpeg($canvas, null, $quality),
                'png'         => imagepng($canvas, null, (int) max(0, min(9, round(9 - ($quality / 100) * 9)))),
                'gif'         => imagegif($canvas),
                'webp'        => imagewebp($canvas, null, $quality),
                'avif'        => imageavif($canvas, null, $quality),
                'bmp'         => imagebmp($canvas),
                default       => null,
            };
            $bytes = (string) ob_get_clean();

            imagedestroy($canvas);

            return strlen($bytes) ? ['bytes' => $bytes, 'width' => $width, 'height' => $height] : null;
        } catch (\Throwable $e) {
            if (isset($image) && $image instanceof \GdImage)  @imagedestroy($image);
            if (isset($canvas) && $canvas instanceof \GdImage) @imagedestroy($canvas);
            throw $e;
        }
    }

    /**
     * Imagick pipeline. Same result, better resampling, and it does not hold
     * the whole decoded bitmap in PHP's memory limit.
     *
     * @param string $source
     * @param array  $params
     * @param string $format
     * @param int    $quality
     * @return array|null
     */
    private static function withImagick(string $source, array $params, string $format, int $quality): ?array
    {
        $image = new \Imagick();

        try {
            $image->readImage($source);

            # Animation: coalesce so every frame is whole, and keep it animated
            # only when the output format can be.
            $animated = $image->getNumberImages() > 1 && in_array($format, ['gif', 'webp'], true);
            if ($image->getNumberImages() > 1) {
                $image = $image->coalesceImages();
                if (!$animated) {
                    $image->setIteratorIndex(0);
                    $frame = $image->getImage();
                    $image->clear();
                    $image = $frame;
                }
            }

            # Metadata is between a third and all of a phone photo's bytes, and
            # it carries location. Stripped, then the colour profile is put back
            # so colours do not shift.
            $profiles = $image->getImageProfiles('icc', true);
            $image->stripImage();
            if (!empty($profiles['icc'])) $image->profileImage('icc', $profiles['icc']);

            $frames = $animated ? $image : [$image];

            foreach ($frames as $frame) {
                if (isset($params['crop'])) {
                    [$x, $y, $w, $h] = array_map('intval', explode(',', $params['crop']));
                    $frame->cropImage($w, $h, $x, $y);
                    $frame->setImagePage(0, 0, 0, 0);
                }

                $geometry = self::geometry($frame->getImageWidth(), $frame->getImageHeight(), $params);

                # Crop first, then scale - same order as the GD path, so both
                # drivers produce the same framing rather than one cropping the
                # result and the other the source.
                if ($geometry['src_w'] !== $frame->getImageWidth() || $geometry['src_h'] !== $frame->getImageHeight()) {
                    $frame->cropImage($geometry['src_w'], $geometry['src_h'], $geometry['src_x'], $geometry['src_y']);
                    $frame->setImagePage(0, 0, 0, 0);
                }

                $frame->resizeImage($geometry['dst_w'], $geometry['dst_h'], \Imagick::FILTER_LANCZOS, 1);

                # pad: the canvas is larger than the image, and the difference is
                # background.
                if ($geometry['canvas_w'] !== $geometry['dst_w'] || $geometry['canvas_h'] !== $geometry['dst_h']) {
                    $frame->setImageBackgroundColor(new \ImagickPixel(isset($params['bg']) ? '#' . $params['bg'] : 'transparent'));
                    $frame->extentImage($geometry['canvas_w'], $geometry['canvas_h'], -$geometry['offset_x'], -$geometry['offset_y']);
                }

                if (isset($params['rotate'])) $frame->rotateImage(new \ImagickPixel('transparent'), (int) $params['rotate']);

                if (isset($params['flip'])) {
                    if ($params['flip'] === 'h' || $params['flip'] === 'both') $frame->flopImage();
                    if ($params['flip'] === 'v' || $params['flip'] === 'both') $frame->flipImage();
                }

                if (!empty($params['gray']))    $frame->transformImageColorspace(\Imagick::COLORSPACE_GRAY);
                if (!empty($params['blur']))    $frame->gaussianBlurImage(0, max(0.5, $params['blur'] / 10));
                if (!empty($params['sharpen'])) $frame->unsharpMaskImage(0, 0.5, $params['sharpen'] / 100, 0.05);

                $frame->setImageCompressionQuality($quality);
                $frame->setImageFormat($format === 'jpg' ? 'jpeg' : $format);
            }

            $image->setImageFormat($format === 'jpg' ? 'jpeg' : $format);
            $image->setImageCompressionQuality($quality);

            $bytes  = $animated ? $image->getImagesBlob() : $image->getImageBlob();
            $width  = $image->getImageWidth();
            $height = $image->getImageHeight();

            $image->clear();

            return strlen($bytes) ? ['bytes' => $bytes, 'width' => $width, 'height' => $height] : null;
        } catch (\Throwable $e) {
            @$image->clear();
            throw $e;
        }
    }
}
