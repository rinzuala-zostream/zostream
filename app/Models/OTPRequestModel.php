<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OTPRequestModel extends Model
{
    protected $table = 'otp_requests';

    protected $fillable = [
        'user_id',
        'otp_code',
        'expires_at',
        'is_verified',
        'created_at'
    ];

    public $timestamps = false; // Because only created_at is handled automatically

    protected $casts = [
        'expires_at' => 'datetime',
        'created_at' => 'datetime',
        'is_verified' => 'boolean'
    ]; 
}
