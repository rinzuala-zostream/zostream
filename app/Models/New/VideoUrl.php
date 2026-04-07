<?php

namespace App\Models\New;

use Illuminate\Database\Eloquent\Model;

class VideoUrl extends Model
{
    protected $table = 'video_urls';

    protected $fillable = [
        'id',
        'movie_id',
        'episode_id',
        'quality',
        'type',
        'url'
    ];

    public $incrementing = false;
    protected $keyType = 'string';
}