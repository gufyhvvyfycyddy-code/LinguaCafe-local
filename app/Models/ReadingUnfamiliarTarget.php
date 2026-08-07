<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReadingUnfamiliarTarget extends Model
{
    use HasFactory;

    public const KIND_WORD = 'word';
    public const KIND_PHRASE = 'phrase';

    protected $fillable = [
        'user_id',
        'language_id',
        'chapter_id',
        'source_revision',
        'occurrence_id',
        'kind',
        'start_word_index',
        'end_word_index',
        'sentence_index',
        'surface',
        'lemma',
        'pos',
        'source_sentence',
    ];

    protected function casts(): array
    {
        return [
            'start_word_index' => 'integer',
            'end_word_index' => 'integer',
            'sentence_index' => 'integer',
        ];
    }
}
