<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ChapterAiReadingAssist extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'language',
        'chapter_id',
        'schema_version',
        'sentence_translations',
        'vocabulary_items',
        'phrase_items',
        'warnings',
        'summary',
    ];

    protected function casts(): array
    {
        return [
            'sentence_translations' => 'array',
            'vocabulary_items' => 'array',
            'phrase_items' => 'array',
            'warnings' => 'array',
            'summary' => 'array',
        ];
    }
}
