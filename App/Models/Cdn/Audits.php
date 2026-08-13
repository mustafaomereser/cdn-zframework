<?php

namespace App\Models\Cdn;

use zFramework\Core\Abstracts\Model;

class Audits extends Model
{
    public $table      = 'cdn_audits';

    # Append-only. An audit row that can be edited is not an audit row.
    public $updated_at = null;
}
