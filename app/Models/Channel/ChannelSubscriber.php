<?php

namespace App\Models\Channel;

use App\Models\UserModel;
use Illuminate\Database\Eloquent\Model;

class ChannelSubscriber extends Model
{
    protected $fillable = [
        'channel_id',
        'user_id',
        'plan_id',
        'subscribed_at',
        'expires_at',
        'status',
    ];

    protected $casts = [
        'subscribed_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function channel()
    {
        return $this->belongsTo(Channel::class);
    }

    public function user()
    {
        return $this->belongsTo(UserModel::class, 'user_id', 'uid');
    }

    public function plan()
    {
        return $this->belongsTo(ChannelSubscriptionPlan::class, 'plan_id');
    }
}
