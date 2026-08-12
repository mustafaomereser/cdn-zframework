<?php

namespace App\Models\Cdn;

use zFramework\Core\Abstracts\Model;

class AccessLogs extends Model
{
    public $table      = 'cdn_access_logs';

    # Append-only: a log line is never updated, so the column would be dead
    # weight on the widest-writing table in the schema.
    public $updated_at = null;
}
