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

    // Relationships
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function movie()
    {
        return $this->belongsTo(MovieModel::class, 'movie_id');
    }

    public function episode()
    {
        return $this->belongsTo(EpisodeModel::class, 'episode_id');
    }

    /**
     * ✅ Dynamic relationship — returns either movie or episode
     */
    public function getContentAttribute()
    {
        return $this->episode_id
            ? $this->episode // if episode exists
            : $this->movie;  // else movie
    }
}
