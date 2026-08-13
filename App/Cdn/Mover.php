<?php

namespace App\Cdn;

use App\Models\Cdn\Buckets;
use App\Models\Cdn\Files;

/**
 * Moving a file to another bucket.
 *
 * Nothing on disk moves. The bytes are content addressed and shared - two files
 * with the same content are one object - so a move is three columns on the file
 * row and the counters either side of it. A 4 GB video changes bucket in the
 * time it takes to write a row.
 *
 * What does change is the URL. The project and the bucket are both segments of
 * it, so every address the file had stops working and its new one starts. That
 * is the whole cost of a move and the panel says so before doing it; there is
 * no redirect left behind, because a CDN that answers 301s is a CDN whose
 * caches hold 301s.
 */
class Mover
{
    /**
     * @param array $file
     * @param array $target The bucket it is going to.
     * @return array{ok:bool,error?:string,path?:string}
     */
    public static function move(array $file, array $target): array
    {
        if ((int) $file['bucket_id'] === (int) $target['id']) return ['ok' => true, 'path' => $file['path']];

        # A suspended project does not change, at either end of this.
        $source = (new Buckets)->closureMode(false)->find((string) $file['bucket_id']);

        if ($source && $reason = Guard::frozen($source)) return ['ok' => false, 'error' => $reason];
        if ($reason = Guard::frozen($target))            return ['ok' => false, 'error' => $reason];

        # The target decides what it accepts, the same as it would for an upload
        # - a bucket that takes only images should not end up holding a zip
        # because somebody moved one in.
        if ($reason = Guard::acceptable($target, (string) $file['mime'], Support::extension((string) $file['path']))) {
            return ['ok' => false, 'error' => $reason];
        }

        $taken = (new Files)
            ->where('bucket_id', $target['id'])
            ->where('path', $file['path'])
            ->closureMode(false)
            ->first();

        if ($taken) return ['ok' => false, 'error' => 'already-exists'];

        # Crossing into another project spends that project's allowance. Within
        # one project the bytes are already counted where they are going.
        if ((int) $target['project_id'] !== (int) $file['project_id']) {
            $project = Registry::project((int) $target['project_id']);

            if ($project && (int) $project['storage_quota'] > 0
                && ((int) $project['storage_used'] + (int) $file['size']) > (int) $project['storage_quota']) {
                return ['ok' => false, 'error' => 'storage-quota-exceeded'];
            }
        }

        (new Files)->where('id', $file['id'])->update([
            'bucket_id'  => (int) $target['id'],
            'project_id' => (int) $target['project_id'],
        ]);

        # The derivatives were built against the old bucket's cache version and
        # are addressed by a signature that includes it. Dropping them is
        # cheaper than reasoning about whether they are still reachable.
        Purger::variantsOf((int) $file['id']);

        Uploader::account($target, (int) $file['size'], 1);

        if ($source) {
            Uploader::account($source, -(int) $file['size'], -1);
            Registry::forgetBucket($source);
        }

        Registry::forgetBucket($target);

        Webhook::fire($target, 'file.moved', [
            'bucket' => $target['slug'],
            'path'   => $file['path'],
            'from'   => $source['slug'] ?? null,
        ]);

        return ['ok' => true, 'path' => $file['path']];
    }
}
