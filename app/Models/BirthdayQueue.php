<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BirthdayQueue extends Model
{
    protected $table = 'birthday_queue';

    protected $fillable = [
        'user_id',
        'name',
        'email',
        'birthday',
        'processed',
        'token',
    ];

    protected $casts = [
        'birthday'  => 'date',
        'processed' => 'boolean',
    ];
}
