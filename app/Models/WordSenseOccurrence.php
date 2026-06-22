<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WordSenseOccurrence extends Model
{
    use HasFactory;

    public const TYPE_WORD = 'word';
    public const TYPE_PHRASE = 'phrase';

    public const STATUS_BOUND = 'bound';
    public const STATUS_PENDING = 'pending';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_IGNORED = 'ignored';

    public const SOURCE_SENSE_MAPPING_IMPORT = 'sense_mapping_import';
    public const SOURCE_MANUAL_VOCAB_BRIDGE = 'manual_vocab_bridge';
    public const SOURCE_MANUAL_SENSE_ADD = 'manual_sense_add';

    protected $fillable = [
        'user_id',
        'language',
        'language_id',
        'word_sense_id',
        'review_card_id',
        'document_id',
        'text_id',
        'chapter_id',
        'sentence_id',
        'sentence_hash',
        'sentence_en',
        'sentence_zh',
        'type',
        'surface',
        'lemma',
        'pos',
        'decision',
        'confidence',
        'evidence',
        'auto_fsrs_allowed',
        'status',
        'source',
        'raw_payload',
    ];

    protected function casts(): array
    {
        return [
            'confidence' => 'float',
            'evidence' => 'array',
            'auto_fsrs_allowed' => 'boolean',
            'raw_payload' => 'array',
        ];
    }

    public function wordSense()
    {
        return $this->belongsTo(WordSense::class);
    }

    public function reviewCard()
    {
        return $this->belongsTo(ReviewCard::class);
    }
}
