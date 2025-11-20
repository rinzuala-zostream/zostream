<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BandwidthLog extends Model
{
    protected $fillable = [
        'user_id',
        'movie_id',
        'episode_id',
        'mb_used',
        'device_type',
    ];

    public function user()
    {
        return $this->belongsTo(UserModel::class);
    }

    public function movie()
    {
        return $this->belongsTo(MovieModel::class);
    }
}
