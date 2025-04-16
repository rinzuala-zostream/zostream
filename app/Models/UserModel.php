<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserModel extends Model
{
    protected $table = 'user';

    protected $casts = [
        'isACActive' => 'boolean',
        'isAccountComplete' => 'boolean',
    ];
    
}
