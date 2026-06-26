<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RescheduleSnapshotItem extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'previous_due_at' => 'datetime',
            'new_due_at' => 'datetime',
            'previous_stability' => 'float',
            'previous_difficulty' => 'float',
            'new_stability' => 'float',
            'new_difficulty' => 'float',
            'skipped' => 'boolean',
            'undone' => 'boolean',
            'undone_at' => 'datetime',
        ];
    }

    public function snapshot()
    {
        return $this->belongsTo(RescheduleSnapshot::class);
    }

    public function reviewCard()
    {
        return $this->belongsTo(ReviewCard::class);
    }
}
