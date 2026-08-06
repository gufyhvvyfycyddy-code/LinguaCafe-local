<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SpecialStudySession extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_ENDED = 'ended';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'user_id',
        'language_id',
        'name',
        'normalized_name',
        'execution_mode',
        'scenario',
        'definition',
        'ordered_card_ids',
        'remaining_card_ids',
        'completed_card_ids',
        'skipped_card_ids',
        'total_candidates',
        'revision',
        'status',
        'completed_at',
        'ended_at',
    ];

    protected function casts(): array
    {
        return [
            'definition' => 'array',
            'ordered_card_ids' => 'array',
            'remaining_card_ids' => 'array',
            'completed_card_ids' => 'array',
            'skipped_card_ids' => 'array',
            'total_candidates' => 'integer',
            'revision' => 'integer',
            'completed_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function actions()
    {
        return $this->hasMany(
            SpecialStudySessionAction::class,
            'special_study_session_id'
        );
    }
}
