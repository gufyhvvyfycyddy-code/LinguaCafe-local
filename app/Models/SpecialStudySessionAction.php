<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SpecialStudySessionAction extends Model
{
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';

    protected $fillable = [
        'special_study_session_id',
        'client_action_id',
        'request_hash',
        'status',
        'operation_id',
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

    public function session()
    {
        return $this->belongsTo(
            SpecialStudySession::class,
            'special_study_session_id'
        );
    }
}
