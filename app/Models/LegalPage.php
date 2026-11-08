<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LegalPage extends Model
{
    use HasFactory;

    protected $fillable = [
        'slug',
        'eyebrow',
        'title',
        'effective_date',
        'intro',
        'sections',
        'is_published',
        'sort_order',
    ];

    protected $casts = [
        'sections' => 'array',
        'is_published' => 'boolean',
        'sort_order' => 'integer',
    ];
}
