<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReadingOccurrenceSenseEvidence extends Model
{
    use HasFactory;

    protected $table = 'reading_occurrence_sense_evidence';

    public const RESOLUTION_MATCHED_EXISTING = 'matched_existing';
    public const RESOLUTION_NEW_SENSE = 'new_sense';
    public const RESOLUTION_EXCLUDED = 'excluded';

    public const SOURCE_USER = 'user';
    public const SOURCE_TRUST_AI = 'trust_ai';

    protected $fillable = [
        'user_id',
        'language_id',
        'chapter_id',
        'source_revision',
        'occurrence_id',
        'target_origin',
        'start_word_index',
        'end_word_index',
        'sentence_index',
        'surface',
        'lemma',
        'pos',
        'resolution',
        'word_sense_id',
        'resolution_source',
        'ai_confidence',
        'ai_package_id',
        'ai_payload_hash',
        'provenance',
    ];

    protected function casts(): array
    {
        return [
            'start_word_index' => 'integer',
            'end_word_index' => 'integer',
            'sentence_index' => 'integer',
            'word_sense_id' => 'integer',
            'provenance' => 'array',
        ];
    }
}
