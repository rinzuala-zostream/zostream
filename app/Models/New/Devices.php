<?php

namespace App\Models\New;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Devices extends Model
{
    use HasFactory;

    protected $table = 'n_devices';

    protected $fillable = [
        'subscription_id',
        'user_id',
        'device_name',
        'device_type',
        'device_token',
        'is_owner_device',
        'last_activity',
        'status',
    ];

    protected $casts = [
        'is_owner_device' => 'boolean',
        'last_activity' => 'datetime',
    ];

    public function subscription()
    {
        return $this->belongsTo(Subscription::class, 'subscription_id');
    }

    public function activeStreams()
    {
        return $this->hasMany(ActiveStream::class, 'device_id');
    }

    public function streamEvents()
    {
        return $this->hasMany(StreamEvent::class, 'device_id');
    }
}