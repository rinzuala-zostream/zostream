<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserModel extends Model
{
    protected $table = 'user';
    public $timestamps = false;

    protected $casts = [
        'isACActive' => 'boolean',
        'isAccountComplete' => 'boolean',
    ];
    
}
