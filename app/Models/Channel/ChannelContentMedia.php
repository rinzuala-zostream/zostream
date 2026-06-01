<?php

namespace App\Models\Channel;

use Illuminate\Database\Eloquent\Model;

class ChannelContentMedia extends Model
{
    public $timestamps = false;

    protected $table = 'channel_content_media';

    protected $fillable = [
        'content_id',
        'media_type',
        'quality',
        'language',
        'url',
        'file_size',
        'created_at',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'created_at' => 'datetime',
    ];

    public function content()
    {
        return $this->belongsTo(ChannelContent::class, 'content_id');
    }
}
