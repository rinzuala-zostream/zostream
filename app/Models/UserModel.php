<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserModel extends Model
{
    protected $table = 'user';
    public $timestamps = false;

    protected $fillable = [
        'call',
        'created_date',
        'device_id',
        'dob',
        'edit_date',
        'img',
        'isACActive',
        'isAccountComplete',
        'khua',
        'lastLogin',
        'mail',
        'name',
        'uid',
        'veng',
        'device_name',
        'token'
    ];

    protected $casts = [
        'isACActive' => 'boolean',
        'isAccountComplete' => 'boolean',
    ];
}
