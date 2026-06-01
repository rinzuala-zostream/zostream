<?php

namespace App\Models\Channel;

use Illuminate\Database\Eloquent\Model;

class ChannelContentPpv extends Model
{
    protected $table = 'channel_content_ppv';

    protected $fillable = [
        'content_id',
        'price',
        'rental_days',
        'is_active',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'rental_days' => 'integer',
        'is_active' => 'boolean',
    ];

    public function content()
    {
        return $this->belongsTo(ChannelContent::class, 'content_id');
    }
}
