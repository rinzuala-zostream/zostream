<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppThemeVersion extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['document' => 'array', 'published_at' => \App\Casts\ThemeUtcDate::class, 'number' => 'integer'];

    public function theme()
    {
        return $this->belongsTo(AppTheme::class, 'theme_id');
    }

    public function schedules()
    {
        return $this->hasMany(AppThemeSchedule::class, 'version_id');
    }
}
