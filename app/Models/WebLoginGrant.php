<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebLoginGrant extends Model
{
    protected $fillable = ['ticket_hash', 'user_id', 'device_id', 'expires_at'];

    protected $casts = ['expires_at' => 'datetime'];
}
