<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AiStudyCardPendingItem extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_DISMISSED = 'dismissed';

    protected $fillable = [
        'user_id',
        'language',
        'language_id',
        'chapter_id',
        'text_block_index',
        'sentence_index',
        'sentence_id',
        'word',
        'normalized_word',
        'surface',
        'lemma',
        'sentence_text',
        'source_payload',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'source_payload' => 'array',
        ];
    }
}
