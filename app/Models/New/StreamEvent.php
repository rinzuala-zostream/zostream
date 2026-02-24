<?php

namespace App\Models\New;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StreamEvent extends Model
{
    use HasFactory;

    protected $table = 'n_stream_events';

    protected $fillable = [
        'subscription_id',
        'device_id',
        'event_type',
        'event_data',
    ];

    protected $casts = [
        'event_data' => 'array',
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