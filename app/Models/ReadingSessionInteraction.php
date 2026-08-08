<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReadingSessionInteraction extends Model
{
    use HasFactory;

    public const TYPE_OPENED = 'opened';
    public const TYPE_HELPED = 'helped';
    public const TYPE_EXPLICIT_RATED = 'explicit_rated';

    protected $fillable = [
        'reading_session_id',
        'user_id',
        'language_id',
        'interaction_key',
        'reading_action_id',
        'occurrence_id',
        'interaction_type',
        'word_sense_id',
        'review_card_id',
        'review_log_id',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'word_sense_id' => 'integer',
            'review_card_id' => 'integer',
            'review_log_id' => 'integer',
            'metadata' => 'array',
        ];
    }
}
