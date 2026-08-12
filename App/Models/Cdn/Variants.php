<?php

namespace App\Models\Cdn;

use zFramework\Core\Abstracts\Model;

class Variants extends Model
{
    public $table      = 'cdn_variants';
    public $_not_found = 'Variant is not found.';

    public function file(array $row): ?array
    {
        return $this->belongsTo(Files::class, $row['file_id']);
    }
}
