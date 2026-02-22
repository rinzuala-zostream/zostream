<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RegisterModel extends Model
{
    protected $table = 'user';
    protected $primaryKey = 'uid';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'call', 'created_date', 'device_id', 'dob', 'edit_date', 'img',
        'isACActive', 'isAccountComplete', 'khua', 'lastLogin', 'mail',
        'name', 'uid', 'veng', 'device_name', 'token',
        'auth_phone', 'is_auth_phone_active'
    ];

    protected $casts = [
        'isACActive' => 'boolean',
        'isAccountComplete' => 'boolean',
        'is_auth_phone_active' => 'boolean',
    ];

    public $timestamps = false;
}