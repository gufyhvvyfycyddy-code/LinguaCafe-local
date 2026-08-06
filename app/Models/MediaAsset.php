<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class MediaAsset extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'public_id', 'user_id', 'language_id', 'sha256', 'storage_name',
        'original_name', 'mime_type', 'extension', 'size_bytes', 'source_kind',
        'copyright_status', 'copyright_source', 'last_accessed_at', 'retained_until',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'last_accessed_at' => 'datetime',
            'retained_until' => 'datetime',
        ];
    }

    public function references()
    {
        return $this->hasMany(MediaReference::class);
    }
}
