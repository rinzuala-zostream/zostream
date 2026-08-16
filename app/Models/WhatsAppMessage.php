<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppMessage extends Model
{
    protected $table = 'whatsapp_messages';

    protected $fillable = [
        'wamid',
        'contact_phone',
        'contact_name',
        'direction',
        'type',
        'body',
        'status',
        'reply_to_wamid',
        'payload',
        'message_at',
        'read_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'message_at' => 'datetime',
        'read_at' => 'datetime',
    ];
}
