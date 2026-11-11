<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppThemeOverride extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['starts_at' => \App\Casts\ThemeUtcDate::class, 'expires_at' => \App\Casts\ThemeUtcDate::class, 'cancelled_at' => \App\Casts\ThemeUtcDate::class];

    public function version()
    {
        return $this->belongsTo(AppThemeVersion::class, 'version_id');
    }
}
