<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WordSenseTag extends Model
{
    protected $hidden = ['user_id', 'language_id', 'normalized_name', 'pivot'];

    protected $fillable = [
        'user_id',
        'language_id',
        'name',
        'normalized_name',
    ];

    public function senses()
    {
        return $this->belongsToMany(
            WordSense::class,
            'word_sense_tag_assignments',
            'word_sense_tag_id',
            'word_sense_id',
        )->withTimestamps();
    }
}
