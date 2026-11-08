<?php

namespace App\Models\Channel;

use Illuminate\Database\Eloquent\Model;

class ChannelContent extends Model
{
    protected $fillable = [
        'channel_id',
        'title',
        'description',
        'content_type',
        'access_type',
        'thumbnail',
        'duration',
        'release_date',
        'status',
    ];

    protected $casts = [
        'duration' => 'integer',
        'release_date' => 'datetime',
    ];

    public function channel()
    {
        return $this->belongsTo(Channel::class);
    }

    public function media()
    {
        return $this->hasMany(ChannelContentMedia::class, 'content_id');
    }

    public function ppv()
    {
        return $this->hasOne(ChannelContentPpv::class, 'content_id');
    }

    public function rentals()
    {
        return $this->hasMany(ChannelContentRental::class, 'content_id');
    }
}
