<?php

namespace App\Models\Cdn;

use zFramework\Core\Abstracts\Model;

class Objects extends Model
{
    public $table      = 'cdn_objects';
    public $_not_found = 'Object is not found.';
}
