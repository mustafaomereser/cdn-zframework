<?php

namespace App\Models\Cdn;

use zFramework\Core\Abstracts\Model;
use zFramework\Core\Traits\DB\softDelete;

class Webhooks extends Model
{
    use softDelete;

    public $table = 'cdn_webhooks';
    public $guard = ['secret'];
}
