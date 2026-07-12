<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReviewCardStateEvent extends Model
{
    public const CREATED_AT = 'created_at';

    public const UPDATED_AT = null;

    public const ACTION_BURY = 'bury';
    public const ACTION_UNBURY = 'unbury';
    public const ACTION_SUSPEND = 'suspend';
    public const ACTION_RESUME = 'resume';
    public const ACTION_ARCHIVE = 'archive';
    public const ACTION_RESTORE = 'restore';

    protected $table = 'review_card_state_events';

    protected $fillable = [
        'user_id',
        'language_id',
        'review_card_id',
        'action',
        'previous_state',
        'new_state',
        'request_id',
        'source',
        'metadata',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'previous_state' => 'array',
            'new_state' => 'array',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function card()
    {
        return $this->belongsTo(ReviewCard::class, 'review_card_id');
    }
}
