<?php

namespace App\Models\Channel;

use App\Models\UserModel;
use Illuminate\Database\Eloquent\Model;

class Channel extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'slug',
        'description',
        'logo',
        'banner',
        'is_verified',
        'status',
    ];

    protected $casts = [
        'is_verified' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(UserModel::class, 'user_id', 'uid');
    }

    public function plans()
    {
        return $this->hasMany(ChannelSubscriptionPlan::class);
    }

    public function subscribers()
    {
        return $this->hasMany(ChannelSubscriber::class);
    }

    public function subscriptionHistory()
    {
        return $this->hasMany(ChannelSubscriptionHistory::class);
    }

    public function contents()
    {
        return $this->hasMany(ChannelContent::class);
    }
}
