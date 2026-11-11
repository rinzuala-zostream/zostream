<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppThemeAuditLog extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['before_json' => 'array', 'after_json' => 'array', 'created_at' => \App\Casts\ThemeUtcDate::class];
}
