<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserStudyBaseRule extends Model
{
    protected $fillable = [
        'user_id',
        'language',
        'surface',
        'study_base',
    ];

    protected $casts = [
        'user_id' => 'integer',
    ];
}
