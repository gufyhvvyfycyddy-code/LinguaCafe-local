<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WordSense extends Model
{
    use HasFactory;

    public const STATUS_AI_SUGGESTED = 'ai_suggested';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'user_id',
        'language',
        'language_id',
        'word_id',
        'encountered_word_id',
        'lemma',
        'surface_form',
        'pos',
        'sense_key',
        'sense_zh',
        'sense_en',
        'aliases_zh',
        'collocations',
        'example_sentence_en',
        'example_sentence_zh',
        'source_text_id',
        'source_chapter_id',
        'sentence_id',
        'sentence_hash',
        'is_context_specific',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'aliases_zh' => 'array',
            'collocations' => 'array',
            'is_context_specific' => 'boolean',
        ];
    }

    public function reviewCard()
    {
        return $this->hasOne(ReviewCard::class, 'target_id')
            ->where('target_type', ReviewCard::TARGET_SENSE);
    }
}
