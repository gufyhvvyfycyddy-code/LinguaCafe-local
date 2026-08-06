<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Operation extends Model
{
    use HasFactory;

    public const TYPE_SENSE_REVIEW_RATING = 'sense_review.rating';

    public const TYPE_MANUAL_BURY_NEXT_DAY = 'review_control.bury_next_day';
    public const TYPE_MANUAL_SUSPEND = 'review_control.suspend';
    public const TYPE_MANUAL_RESUME = 'review_control.resume';
    public const TYPE_MANUAL_SET_DUE = 'review_control.set_due';
    public const TYPE_MANUAL_DUE_NOW = 'review_control.due_now';
    public const TYPE_MANUAL_RESET_NEW = 'review_control.reset_new';

    public const SCOPE_SESSION = 'session';

    public const SCOPE_DEVICE = 'device';

    public const SCOPE_REVIEW_CONTROL = 'review_control';

    public const STATUS_APPLIED = 'applied';

    public const STATUS_UNDONE = 'undone';

    public const STATUS_SUPERSEDED = 'superseded';

    protected $fillable = [
        'operation_id',
        'user_id',
        'language_id',
        'mobile_device_id',
        'source_channel',
        'operation_type',
        'action_payload',
        'request_fingerprint',
        'client_occurred_at',
        'client_sequence',
        'batch_id',
        'scope_type',
        'scope_id',
        'status',
        'version',
        'review_card_id',
        'review_log_id',
        'review_session_id',
        'last_transition_sequence',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'last_transition_sequence' => 'integer',
            'action_payload' => 'array',
            'client_occurred_at' => 'datetime',
            'client_sequence' => 'integer',
        ];
    }

    public function isManualReviewControl(): bool
    {
        return str_starts_with($this->operation_type, 'review_control.');
    }

    public function device()
    {
        return $this->belongsTo(MobileDevice::class, 'mobile_device_id');
    }

    public function reviewCard()
    {
        return $this->belongsTo(ReviewCard::class);
    }

    public function reviewLog()
    {
        return $this->belongsTo(ReviewLog::class);
    }

    public function changes()
    {
        return $this->hasMany(OperationChange::class, 'operation_record_id');
    }
}
