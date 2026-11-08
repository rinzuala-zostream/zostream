<?php

namespace App\Models\New;

use Illuminate\Database\Eloquent\Model;

class ReelView extends Model
{
    protected $table = 'reel_views';

    public $timestamps = false;

    protected $fillable = [
        'reel_id',
        'user_id',
        'watch_time_ms',
        'duration_ms',
        'completion_rate',
        'completed',
        'skipped',
        'created_at'
    ];
}
