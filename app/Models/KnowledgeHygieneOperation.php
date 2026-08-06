<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KnowledgeHygieneOperation extends Model
{
    use HasFactory;

    public const TYPE_FIND_REPLACE = 'find_replace';
    public const TYPE_SAFE_DELETE = 'safe_delete';
    public const TYPE_MERGE = 'merge';

    public const STATUS_APPLIED = 'applied';
    public const STATUS_UNDONE = 'undone';

    protected $fillable = [
        'operation_id',
        'user_id',
        'language_id',
        'operation_type',
        'status',
        'subject_ids',
        'before_snapshot',
        'after_snapshot',
        'preview_fingerprint',
        'metadata',
        'undone_at',
    ];

    protected function casts(): array
    {
        return [
            'subject_ids' => 'array',
            'before_snapshot' => 'array',
            'after_snapshot' => 'array',
            'metadata' => 'array',
            'undone_at' => 'datetime',
        ];
    }
}
