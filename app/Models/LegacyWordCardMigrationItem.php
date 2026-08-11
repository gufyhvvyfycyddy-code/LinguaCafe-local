<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LegacyWordCardMigrationItem extends Model
{
    protected $fillable = [
        'run_id',
        'legacy_review_card_id',
        'encountered_word_id',
        'word_sense_id',
        'sense_review_card_id',
        'user_id',
        'language_id',
        'created_sense_card',
        'classification',
        'primary_reason_code',
        'reason_codes',
        'before_classification_evidence',
        'before_classification_fingerprint',
        'after_classification_evidence',
        'after_classification_fingerprint',
        'before_legacy_snapshot',
        'before_legacy_fingerprint',
        'after_legacy_snapshot',
        'after_legacy_fingerprint',
        'before_sense_snapshot',
        'before_sense_fingerprint',
        'after_sense_snapshot',
        'after_sense_fingerprint',
    ];

    protected function casts(): array
    {
        return [
            'created_sense_card' => 'boolean',
            'reason_codes' => 'array',
            'before_classification_evidence' => 'array',
            'after_classification_evidence' => 'array',
            'before_legacy_snapshot' => 'array',
            'after_legacy_snapshot' => 'array',
            'before_sense_snapshot' => 'array',
            'after_sense_snapshot' => 'array',
        ];
    }
}
