<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RedemptionCode extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'expires_at' => 'datetime',
        'redeemed_at' => 'datetime',
        'result' => 'array',
        'rental_days' => 'integer',
    ];
}
