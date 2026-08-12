<?php

namespace App\Models\Cdn;

use zFramework\Core\Abstracts\Model;
use zFramework\Core\Traits\DB\softDelete;

class ApiKeys extends Model
{
    use softDelete;

    public $table      = 'cdn_api_keys';
    public $_not_found = 'API key is not found.';

    # Neither form of the secret leaves the server, not even to the panel that
    # created it. The plain secret is shown once, at creation, and never again.
    public $guard      = ['secret_hash', 'secret_cipher'];

    public function project(array $row): ?array
    {
        return $this->belongsTo(Projects::class, $row['project_id']);
    }
}
