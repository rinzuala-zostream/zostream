<?php

namespace App\Models\New;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    use HasFactory;

    protected $table = 'n_subscriptions';

    protected $fillable = [
        'user_id',
        'plan_id',
        'start_at',
        'end_at',
        'is_active',
        'renewed_by',
    ];

    protected $dates = ['start_at', 'end_at'];

    public function plan()
    {
        return $this->belongsTo(Plan::class, 'plan_id');
    }

    public function devices()
    {
        return $this->hasMany(Devices::class, 'subscription_id');
    }

    public function activeStreams()
    {
        return $this->hasMany(ActiveStream::class, 'subscription_id');
    }

    public function streamEvents()
    {
        return $this->hasMany(StreamEvent::class, 'subscription_id');
    }
}