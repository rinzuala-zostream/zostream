<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WatchSession extends Model
{
    protected $fillable = [
        'user_id',
        'movie_id',
        'episode_id',
        'seconds_watched',
        'device_type',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function movie()
    {
        return $this->belongsTo(MovieModel::class);
    }
}
