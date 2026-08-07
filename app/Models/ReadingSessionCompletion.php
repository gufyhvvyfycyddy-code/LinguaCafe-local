<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReadingSessionCompletion extends Model
{
    use HasFactory;

    protected $fillable = [
        'reading_session_id',
        'user_id',
        'language_id',
        'chapter_id',
        'source_revision',
        'result',
    ];

    protected function casts(): array
    {
        return [
            'result' => 'array',
        ];
    }
}
