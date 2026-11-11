<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppThemeSchedule extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['start_at' => \App\Casts\ThemeUtcDate::class, 'end_at' => \App\Casts\ThemeUtcDate::class];
}
