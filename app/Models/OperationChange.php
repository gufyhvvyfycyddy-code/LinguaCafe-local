<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OperationChange extends Model
{
    use HasFactory;

    public const TRANSITION_APPLY = 'apply';

    public const TRANSITION_UNDO = 'undo';

    public const TRANSITION_REDO = 'redo';

    public const TRANSITION_SUPERSEDE = 'supersede';

    protected $fillable = [
        'operation_record_id',
        'transition',
        'from_status',
        'to_status',
        'version',
        'actor_mobile_device_id',
        'actor_source_channel',
        'client_action_id',
        'before_state',
        'after_state',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'before_state' => 'array',
            'after_state' => 'array',
        ];
    }

    public function operation()
    {
        return $this->belongsTo(Operation::class, 'operation_record_id');
    }
}
