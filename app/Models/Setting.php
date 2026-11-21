<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Setting extends Model
{
    use HasFactory;

    public const DEFAULT_REVIEW_INTERVALS = [
        '-7' => [0],
        '-6' => [1],
        '-5' => [2, 3],
        '-4' => [6, 7, 8],
        '-3' => [15, 16, 17, 18],
        '-2' => [37, 38, 39, 40, 41, 42],
        '-1' => [94, 95, 96, 97, 98, 99, 100, 101],
    ];

    public function decode() {
        return json_decode($this->value);
    }
}
