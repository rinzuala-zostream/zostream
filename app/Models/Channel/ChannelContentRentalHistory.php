<?php

namespace App\Models\Channel;

use App\Models\UserModel;
use Illuminate\Database\Eloquent\Model;

class ChannelContentRentalHistory extends Model
{
    public $timestamps = false;

    protected $table = 'channel_content_rental_history';

    protected $fillable = [
        'user_id',
        'content_id',
        'amount',
        'transaction_id',
        'payment_method',
        'rented_at',
        'expires_at',
        'status',
        'created_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'rented_at' => 'datetime',
        'expires_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(UserModel::class, 'user_id', 'uid');
    }

    public function content()
    {
        return $this->belongsTo(ChannelContent::class, 'content_id');
    }
}
