<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RegisterModel extends Model
{
    protected $table = 'user';
    protected $primaryKey = 'uid'; // ✅ Set UID as the primary key
    public $incrementing = false;  // ✅ If uid is not auto-increment
    protected $keyType = 'string'; // ✅ If uid is a string

    protected $fillable = [
        'call', 'created_date', 'device_id', 'dob', 'edit_date', 'img',
        'isACActive', 'isAccountComplete', 'khua', 'lastLogin', 'mail',
        'name', 'uid', 'veng', 'device_name', 'token'
    ];

    protected $casts = [
        'edit_date' => 'datetime',
        'isACActive' => 'boolean',
        'isAccountComplete' => 'boolean',
    ];

    public $timestamps = false;
}
