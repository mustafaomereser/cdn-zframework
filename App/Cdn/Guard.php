<?php

namespace App\Cdn;

/**
 * Everything that can refuse a delivery request, in the order it is cheapest to
 * refuse it.
 *
 * Nothing here reads the filesystem or the object row - the point is to decide
 * before any of that work happens. Each check returns null when it is satisfied
 * and a refusal otherwise, so the caller reads as a list of reasons rather than
 * a nest of conditions.
 */
class Guard
{
    /**
     * Refusal shape shared by every check.
     *
     * @param int    $status
     * @param string $reason
     * @return array{status:int,reason:string}
     */
    private static function deny(int $status, string $reason): array
    {
        return ['status' => $status, 'reason' => $reason];
    }

    /**
     * Run the whole chain for a delivery request.
     *
     * @param array      $bucket
     * @param array|null $project
     * @param string     $path    bucket/path, as signed
     * @return array|null  Refusal, or null to proceed.
     */
    public static function delivery(array $bucket, ?array $project, string $path): ?array
    {
        if (($bucket['status'] ?? 'active') !== 'active') return self::deny(404, 'bucket-inactive');
        if ($project && ($project['status'] ?? 'active') !== 'active') return self::deny(403, 'project-suspended');

        # Private buckets have no public URL at all - the management API is the
        # only way in. Answered as 404 rather than 403: whether a private bucket
        # exists is itself information.
        if (($bucket['visibility'] ?? 'public') === 'private') return self::deny(404, 'bucket-private');

        if ($refusal = self::agent())        return $refusal;
        if ($refusal = self::address($bucket)) return $refusal;
        if ($refusal = self::signature($bucket, $path)) return $refusal;
        if ($refusal = self::referer($bucket)) return $refusal;
        if ($refusal = self::quota($project))  return $refusal;
        if ($refusal = self::rate())           return $refusal;

        return null;
    }

    /**
     * @return array|null
     */
    public static function agent(): ?array
    {
        $blocked = (array) Support::config('security.block-agents', []);
        if (!count($blocked)) return null;

        $agent = strtolower((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        foreach ($blocked as $needle) {
            $needle = strtolower(trim((string) $needle));
            if ($needle !== '' && strstr($agent, $needle)) return self::deny(403, 'agent-blocked');
        }

        return null;
    }

    /**
     * Address rules: bucket first, then the global ones.
     *
     * An allow list is exclusive - anything not on it is denied. That is the
     * only reading that makes an allow list worth having.
     *
     * @param array $bucket
     * @return array|null
     */
    public static function address(array $bucket): ?array
    {
        $ip    = function_exists('ip') ? (string) ip() : '';
        $rules = Support::json($bucket['ip_rules'] ?? null);

        $deny  = array_merge((array) Support::config('security.deny-ip', []),  (array) ($rules['deny'] ?? []));
        $allow = array_merge((array) Support::config('security.allow-ip', []), (array) ($rules['allow'] ?? []));

        foreach ($deny as $candidate) if (self::ipMatches($ip, (string) $candidate)) return self::deny(403, 'ip-denied');

        if (count($allow)) {
            foreach ($allow as $candidate) if (self::ipMatches($ip, (string) $candidate)) return null;
            return self::deny(403, 'ip-not-allowed');
        }

        return null;
    }

    /**
     * Exact address, or a CIDR block.
     *
     * @param string $ip
     * @param string $rule
     * @return bool
     */
    public static function ipMatches(string $ip, string $rule): bool
    {
        $rule = trim($rule);
        if ($rule === '' || $ip === '') return false;
        if ($rule === $ip) return true;
        if (!strstr($rule, '/')) return false;

        [$subnet, $bits] = explode('/', $rule, 2);
        $bits = (int) $bits;

        $address = inet_pton($ip);
        $network = inet_pton($subnet);
        if ($address === false || $network === false || strlen($address) !== strlen($network)) return false;

        $bytes = intdiv($bits, 8);
        $rest  = $bits % 8;

        if ($bytes && strncmp($address, $network, $bytes) !== 0) return false;
        if (!$rest) return true;

        $mask = chr(0xFF << (8 - $rest) & 0xFF);
        return (($address[$bytes] ?? "\0") & $mask) === (($network[$bytes] ?? "\0") & $mask);
    }

    /**
     * Signature requirement.
     *
     * @param array  $bucket
     * @param string $path
     * @return array|null
     */
    public static function signature(array $bucket, string $path): ?array
    {
        $required = ($bucket['visibility'] ?? 'public') === 'signed' || !empty($bucket['signed_only']);

        # A transform on a bucket that does not otherwise need signing still can:
        # transform.signed-only closes the "walk ?w=1..5000 and fill the disk"
        # hole without making the plain object URLs signed.
        if (!$required && Support::config('transform.signed-only', false) && Transform::requested()) $required = true;

        if (!$required) return null;

        $result = Signature::verify($path, $_GET, $bucket);

        return $result === true ? null : self::deny(403, "signature-$result");
    }

    /**
     * Hotlink protection.
     *
     * @param array $bucket
     * @return array|null
     */
    public static function referer(array $bucket): ?array
    {
        $policy = Support::json($bucket['referers'] ?? null);
        if (!count($policy)) $policy = (array) Support::config('security.referers', []);

        $mode = strtolower((string) ($policy['mode'] ?? 'off'));
        if ($mode === 'off' || $mode === '') return null;

        $referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');

        if ($referer === '') return ($policy['allow-empty'] ?? true) ? null : self::deny(403, 'referer-empty');

        $host = strtolower((string) parse_url($referer, PHP_URL_HOST));
        if ($host === '') return ($policy['allow-empty'] ?? true) ? null : self::deny(403, 'referer-unparsable');

        $matched = Support::hostMatches($host, (array) ($policy['list'] ?? []));

        if ($mode === 'allow' && !$matched) return self::deny(403, 'referer-not-allowed');
        if ($mode === 'deny'  && $matched)  return self::deny(403, 'referer-denied');

        return null;
    }

    /**
     * Transfer quota.
     *
     * Storage quota is not checked here: refusing to serve bytes that are
     * already stored helps nobody. It is enforced on upload.
     *
     * @param array|null $project
     * @return array|null
     */
    public static function quota(?array $project): ?array
    {
        if (!$project) return null;

        $quota  = (int) ($project['bandwidth_quota'] ?? 0);
        if ($quota <= 0) return null;
        if (($project['bandwidth_period'] ?? null) !== date('Y-m')) return null;

        # 509 is not in the RFCs but is what hosting panels send, and it is
        # unambiguous in a log in a way that 403 is not.
        return (int) ($project['bandwidth_used'] ?? 0) >= $quota ? self::deny(509, 'bandwidth-exceeded') : null;
    }

    /**
     * Per-address rate limiting for reads.
     *
     * @return array|null
     */
    public static function rate(): ?array
    {
        $result = RateLimiter::check('ip', function_exists('ip') ? (string) ip() : 'unknown');
        if ($result === null || $result['allowed']) return null;

        return self::deny(429, 'rate-limited') + ['retry-after' => max(1, $result['reset'] - time())];
    }

    /**
     * Whether anything may be written to this bucket at all.
     *
     * Suspending a project stops it serving. It has to stop it changing too, or
     * "suspended" means "the urls are off but carry on filling the disk" - and
     * an account suspended for what it was storing can go on storing it, and
     * delete what it was suspended over.
     *
     * Reads are untouched: the panel still lists the files, and the owner can
     * still see what they have. Nothing is destroyed by a suspension.
     *
     * @param array $bucket
     * @return string|null Reason, or null when writable.
     */
    public static function frozen(array $bucket): ?string
    {
        if (($bucket['status'] ?? 'active') !== 'active') return 'bucket-inactive';

        $project = Registry::project((int) ($bucket['project_id'] ?? 0));

        if ($project && ($project['status'] ?? 'active') !== 'active') return 'project-suspended';

        return null;
    }

    /**
     * Whether a mime type may be stored in a bucket.
     *
     * @param array  $bucket
     * @param string $mime
     * @param string $extension
     * @return string|null  Reason, or null when acceptable.
     */
    public static function acceptable(array $bucket, string $mime, string $extension): ?string
    {
        $extension = strtolower(ltrim($extension, '.'));

        $blocked = array_map('strtolower', (array) Support::config('upload.blocked-ext', []));
        if (in_array($extension, $blocked, true)) return 'extension-blocked';

        # Double extensions: photo.php.jpg is a jpg to this bucket and a script
        # to a server configured to look at every dot.
        foreach (explode('.', strtolower(basename($extension))) as $part) {
            if (in_array($part, $blocked, true)) return 'extension-blocked';
        }

        $allowedGlobal = array_map('strtolower', (array) Support::config('upload.allowed-ext', []));
        if (count($allowedGlobal) && !in_array($extension, $allowedGlobal, true)) return 'extension-not-allowed';

        $allowedBucket = array_map('strtolower', Support::json($bucket['allowed_ext'] ?? null));
        if (count($allowedBucket) && !in_array($extension, $allowedBucket, true)) return 'extension-not-allowed';

        $mimes = Support::json($bucket['allowed_mimes'] ?? null);
        if (count($mimes)) {
            $mime  = strtolower(strtok($mime, ';'));
            $match = false;
            foreach ($mimes as $candidate) {
                $candidate = strtolower(trim((string) $candidate));
                if ($candidate === $mime || (str_ends_with($candidate, '/*') && str_starts_with($mime, rtrim($candidate, '*')))) {
                    $match = true;
                    break;
                }
            }
            if (!$match) return 'mime-not-allowed';
        }

        return null;
    }
}
