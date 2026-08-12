<?php

namespace App\Models\Cdn;

use zFramework\Core\Abstracts\Model;

class Uploads extends Model
{
    public $table      = 'cdn_uploads';
    public $_not_found = 'Upload session is not found.';
}
