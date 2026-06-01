<?php

namespace App\Models\Channel;

use App\Models\UserModel;
use Illuminate\Database\Eloquent\Model;

class ChannelContentRental extends Model
{
    protected $fillable = [
        'user_id',
        'content_id',
        'rented_at',
        'expires_at',
        'status',
    ];

    protected $casts = [
        'rented_at' => 'datetime',
        'expires_at' => 'datetime',
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
