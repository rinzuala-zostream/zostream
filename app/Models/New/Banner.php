<?php

namespace App\Models\New;

use Illuminate\Database\Eloquent\Model;

class Banner extends Model
{

    protected $table = 'banner';

    protected $casts = [
        'is_active' => 'boolean',
        'age_restriction_enabled' => 'boolean',
        'requires_parental_pin' => 'boolean',
        'start_date' => 'datetime',
        'end_date' => 'datetime',
    ];

    public $timestamps = false;

    protected $fillable = [
        'title',
        'description',
        'type',
        'media_type',
        'media_url',
        'thumbnail_url',
        'target_type',
        'target_id',
        'target_url',
        'priority',
        'is_active',
        'age_restriction_enabled',
        'min_age',
        'max_age',
        'age_rating',
        'requires_parental_pin',
        'start_date',
        'end_date',
        'button_text',
    ];
}
