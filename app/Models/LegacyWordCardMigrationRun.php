<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LegacyWordCardMigrationRun extends Model
{
    public const STATE_APPLIED = 'applied';

    public const STATE_ROLLED_BACK = 'rolled_back';

    protected $fillable = [
        'schema_version',
        'classifier_schema_version',
        'run_uuid',
        'report_fingerprint',
        'plan_fingerprint',
        'backup_id',
        'backup_manifest_sha256',
        'backup_payload_sha256',
        'filters',
        'counts',
        'state',
        'applied_at',
        'rolled_back_at',
    ];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'counts' => 'array',
            'applied_at' => 'datetime',
            'rolled_back_at' => 'datetime',
        ];
    }

    public function items()
    {
        return $this->hasMany(LegacyWordCardMigrationItem::class, 'run_id');
    }
}
