<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RescheduleSnapshot extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'undone_at' => 'datetime',
            'total_cards' => 'integer',
            'applied_count' => 'integer',
            'skipped_count' => 'integer',
            'newly_due_today' => 'integer',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function items()
    {
        return $this->hasMany(RescheduleSnapshotItem::class);
    }
}
