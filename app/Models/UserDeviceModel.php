<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class UserDeviceModel extends Model
{
    use HasFactory;

    protected $table = 'user_devices'; // Ensure the table name matches your database

    protected $fillable = [
        'user_id',
        'device_id',
        'device_name',
        'role', // 'owner' or 'shared'
        'last_login'
    ];

    public $timestamps = false; // Disable timestamps if not using created_at/updated_at
}
