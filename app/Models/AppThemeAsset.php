<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppThemeAsset extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['created_at' => \App\Casts\ThemeUtcDate::class];
}
