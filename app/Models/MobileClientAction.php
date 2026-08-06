<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MobileClientAction extends Model
{
    use HasFactory;

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    protected $fillable = [
        'operation_id',
        'user_id',
        'mobile_device_id',
        'action_type',
        'client_action_id',
        'request_hash',
        'status',
        'response_status',
        'response_body',
    ];

    protected function casts(): array
    {
        return [
            'response_status' => 'integer',
            'response_body' => 'array',
        ];
    }

    public function device()
    {
        return $this->belongsTo(MobileDevice::class, 'mobile_device_id');
    }
}
