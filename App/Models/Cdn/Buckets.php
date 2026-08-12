<?php

namespace App\Models\Cdn;

use zFramework\Core\Abstracts\Model;
use zFramework\Core\Traits\DB\softDelete;

class Buckets extends Model
{
    use softDelete;

    public $table      = 'cdn_buckets';
    public $_not_found = 'Bucket is not found.';

    # Never sent to a client: it signs URLs.
    public $guard      = ['signing_key'];

    public function files(array $row): array
    {
        return $this->hasMany(Files::class, $row['id'], 'bucket_id');
    }

    public function project(array $row): ?array
    {
        return $this->belongsTo(Projects::class, $row['project_id']);
    }

    public function filesCount(array $row): int
    {
        return $this->hasManyCount(Files::class, $row['id'], 'bucket_id');
    }
}
