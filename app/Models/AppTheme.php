<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppTheme extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['revision' => 1];

    protected $casts = ['draft' => 'array', 'revision' => 'integer'];

    public function versions()
    {
        return $this->hasMany(AppThemeVersion::class, 'theme_id')->orderByDesc('number');
    }
}
