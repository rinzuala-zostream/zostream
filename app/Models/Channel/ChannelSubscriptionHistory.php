<?php

namespace App\Models\Channel;

use App\Models\UserModel;
use Illuminate\Database\Eloquent\Model;

class ChannelSubscriptionHistory extends Model
{
    public $timestamps = false;

    protected $table = 'channel_subscription_history';

    protected $fillable = [
        'channel_id',
        'user_id',
        'plan_id',
        'amount',
        'transaction_id',
        'payment_method',
        'start_date',
        'end_date',
        'status',
        'created_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'start_date' => 'datetime',
        'end_date' => 'datetime',
        'created_at' => 'datetime',
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
