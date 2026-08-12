<?php

namespace App\Models\Cdn;

use zFramework\Core\Abstracts\Model;
use zFramework\Core\Traits\DB\softDelete;

class Projects extends Model
{
    use softDelete;

    public $table      = 'cdn_projects';
    public $_not_found = 'Project is not found.';

    public function buckets(array $row): array
    {
        return $this->hasMany(Buckets::class, $row['id'], 'project_id');
    }

    public function keys(array $row): array
    {
        return $this->hasMany(ApiKeys::class, $row['id'], 'project_id');
    }

    /**
     * Whether the stored bytes are still inside the quota.
     * @param array $row
     * @return bool
     */
    public function overStorage(array $row): bool
    {
        return $row['storage_quota'] > 0 && $row['storage_used'] >= $row['storage_quota'];
    }

    /**
     * Whether this month's transfer is spent.
     *
     * The counter belongs to a month (`bandwidth_period`); a row still carrying
     * last month's period is read as zero rather than reset here, so a read path
     * never writes.
     *
     * @param array $row
     * @return bool
     */
    public function overBandwidth(array $row): bool
    {
        if (!($row['bandwidth_quota'] > 0)) return false;
        if (($row['bandwidth_period'] ?? null) !== date('Y-m')) return false;

        return $row['bandwidth_used'] >= $row['bandwidth_quota'];
    }
}
