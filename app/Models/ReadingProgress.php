<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReadingProgress extends Model
{
    use HasFactory;

    protected $table = 'reading_progress';

    protected $fillable = [
        'user_id',
        'language_id',
        'chapter_id',
        'source_revision',
        'canonical_token_index',
        'furthest_canonical_token_index',
        'position_occurred_at',
        'last_mobile_device_id',
        'client_sequence',
    ];

    protected function casts(): array
    {
        return [
            'canonical_token_index' => 'integer',
            'furthest_canonical_token_index' => 'integer',
            'position_occurred_at' => 'datetime',
            'last_mobile_device_id' => 'integer',
            'client_sequence' => 'integer',
        ];
    }
}
