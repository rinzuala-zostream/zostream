<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserModel extends Model
{
    protected $table = 'user';
    protected $primaryKey = 'num';
    public $incrementing = true;
    protected $keyType = 'int';
    public $timestamps = false;

    // Location and gender are returned only by the authenticated mobile-profile endpoint.
    protected $hidden = ['profile_district_code', 'profile_village_code', 'profile_gender'];

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
        'country_code',
        'auth_phone',
        'is_auth_phone_active',
        'name',
        'uid',
        'veng',
        'device_name',
        'token'
    ];

    protected $casts = [
        'isACActive' => 'boolean',
        'isAccountComplete' => 'boolean',
        'is_auth_phone_active' => 'boolean',
    ];
}
