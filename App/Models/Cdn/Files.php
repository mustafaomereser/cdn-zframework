<?php

namespace App\Models\Cdn;

use zFramework\Core\Abstracts\Model;
use zFramework\Core\Traits\DB\softDelete;

class Files extends Model
{
    use softDelete;

    public $table      = 'cdn_files';
    public $_not_found = 'File is not found.';

    public function bucket(array $row): ?array
    {
        return $this->belongsTo(Buckets::class, $row['bucket_id']);
    }

    public function variants(array $row): array
    {
        return $this->hasMany(Variants::class, $row['id'], 'file_id');
    }

    public function variantsCount(array $row): int
    {
        return $this->hasManyCount(Variants::class, $row['id'], 'file_id');
    }
}
