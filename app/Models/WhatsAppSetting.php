<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppSetting extends Model
{
    protected $table = 'whatsapp_settings';

    protected $fillable = [
        'verify_token',
        'auto_reply_enabled',
        'auto_reply_message',
    ];

    protected $casts = [
        'verify_token' => 'encrypted',
        'auto_reply_enabled' => 'boolean',
    ];

    public static function current(): self
    {
        return static::query()->firstOrCreate([]);
    }
}
