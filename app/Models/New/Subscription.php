<?php

namespace App\Models\New;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class Subscription extends Model
{
    use HasFactory;

    protected $table = 'n_subscriptions';

    protected $fillable = [
        'user_id',
        'plan_id',
        'start_at',
        'end_at',
        'is_active',
        'renewed_by',
    ];

    protected $casts = [
        'start_at' => 'datetime',
        'end_at'   => 'datetime',
        'is_active' => 'boolean',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function plan()
    {
        return $this->belongsTo(Plan::class, 'plan_id');
    }

    public function devices()
    {
        return $this->hasMany(Devices::class, 'subscription_id');
    }

    public function activeStreams()
    {
        return $this->hasMany(ActiveStream::class, 'subscription_id');
    }

    public function streamEvents()
    {
        return $this->hasMany(StreamEvent::class, 'subscription_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers (Very Important for Zo Stream)
    |--------------------------------------------------------------------------
    */

    public function isActive(): bool
    {
        return $this->is_active &&
               $this->end_at &&
               $this->end_at->isFuture();
    }

    public function extend(): void
    {
        if (!$this->plan) {
            return;
        }

        $duration = $this->plan->duration_days;

        $newExpiry = $this->end_at && $this->end_at->isFuture()
            ? $this->end_at->copy()->addDays($duration)
            : Carbon::now()->addDays($duration);

        $this->update([
            'start_at' => $this->start_at ?? now(),
            'end_at'   => $newExpiry,
            'is_active' => true,
        ]);
    }

    public function deactivate(): void
    {
        $this->update([
            'is_active' => false,
        ]);
    }
}