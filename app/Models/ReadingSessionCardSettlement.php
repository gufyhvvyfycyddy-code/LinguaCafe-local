<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReadingSessionCardSettlement extends Model
{
    use HasFactory;

    protected $fillable = [
        'reading_session_id',
        'user_id',
        'language_id',
        'review_card_id',
        'word_sense_id',
        'review_log_id',
        'rating',
    ];
}
