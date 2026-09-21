<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HomeSection extends Model
{
    protected $fillable = [
        'section_key',
        'source_key',
        'title',
        'position',
        'is_enabled',
    ];

    protected $casts = [
        'position' => 'integer',
        'is_enabled' => 'boolean',
    ];
}
