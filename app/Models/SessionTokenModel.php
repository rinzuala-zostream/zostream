<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SessionTokenModel extends Model
{
    protected $table = 'session_tokens';
    protected $fillable = [
        'user_id',
        'access_token',
        'refresh_token',
        'access_expires_at',
        'refresh_expires_at',
        'device_name',
        'device_id',
    ];

    protected $casts = [
        'access_expires_at' => 'datetime',
        'refresh_expires_at' => 'datetime',
    ];
}