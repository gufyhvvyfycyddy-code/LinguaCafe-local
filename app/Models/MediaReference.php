<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MediaReference extends Model
{
    public const ROLE_WORD_PRONUNCIATION = 'word_pronunciation';
    public const ROLE_EXAMPLE_AUDIO = 'example_audio';
    public const ROLES = [self::ROLE_WORD_PRONUNCIATION, self::ROLE_EXAMPLE_AUDIO];

    protected $fillable = [
        'public_id', 'user_id', 'language_id', 'media_asset_id',
        'word_sense_id', 'role', 'slot_key', 'source_text',
    ];

    public function asset()
    {
        return $this->belongsTo(MediaAsset::class, 'media_asset_id')->withTrashed();
    }

    public function wordSense()
    {
        return $this->belongsTo(WordSense::class);
    }
}
