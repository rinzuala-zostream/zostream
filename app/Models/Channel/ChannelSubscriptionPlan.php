<?php

namespace App\Models\Channel;

use Illuminate\Database\Eloquent\Model;

class ChannelSubscriptionPlan extends Model
{
    protected $fillable = [
        'channel_id',
        'name',
        'duration_days',
        'price',
        'discount_percent',
        'final_price',
        'is_active',
    ];

    protected $casts = [
        'duration_days' => 'integer',
        'price' => 'decimal:2',
        'discount_percent' => 'decimal:2',
        'final_price' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function channel()
    {
        return $this->belongsTo(Channel::class);
    }

    public function subscribers()
    {
        return $this->hasMany(ChannelSubscriber::class, 'plan_id');
    }
}
