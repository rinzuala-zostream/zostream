<?php

namespace App\Models\New;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ActiveStream extends Model
{
    use HasFactory;

    protected $table = 'n_active_streams';

    public $timestamps = false;

    protected $fillable = [
        'subscription_id',
        'device_id',
        'device_type',
        'content_type',
        'content_id',
        'content_key',
        'stream_token',
        'started_at',
        'last_ping',
        'viewed_at',
        'status',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'last_ping' => 'datetime',
        'viewed_at' => 'datetime',
    ];

    public function subscription()
    {
        return $this->belongsTo(Subscription::class, 'subscription_id');
    }

    public function device()
    {
        return $this->belongsTo(Devices::class, 'device_id');
    }
}
