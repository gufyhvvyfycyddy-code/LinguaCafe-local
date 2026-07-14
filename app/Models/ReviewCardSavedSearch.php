<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReviewCardSavedSearch extends Model
{
    protected $hidden = ['user_id', 'language_id', 'normalized_name'];

    protected $fillable = [
        'user_id',
        'language_id',
        'name',
        'normalized_name',
        'filter_state_version',
        'filter_state',
    ];

    protected function casts(): array
    {
        return [
            'filter_state_version' => 'integer',
            'filter_state' => 'array',
        ];
    }
}
