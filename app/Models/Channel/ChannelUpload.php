<?php

namespace App\Models\Channel;

use Illuminate\Database\Eloquent\Model;

class ChannelUpload extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $hidden = ['user_id'];

    protected $casts = ['size' => 'integer', 'chunk_size' => 'integer', 'expires_at' => 'datetime'];

    public function directory(): string
    {
        return $this->channel_id.'/'.$this->id;
    }
}
