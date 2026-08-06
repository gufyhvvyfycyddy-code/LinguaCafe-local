<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\PersonalAccessToken;

class MobileDevice extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'device_uuid',
        'platform',
        'device_name',
        'app_version',
        'personal_access_token_id',
        'last_active_at',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'last_active_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function token()
    {
        return $this->belongsTo(PersonalAccessToken::class, 'personal_access_token_id');
    }

    public function clientActions()
    {
        return $this->hasMany(MobileClientAction::class);
    }
}
