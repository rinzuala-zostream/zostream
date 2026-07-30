<?php

namespace App\Models\New;

use App\Models\UserModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
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
        'start_at' => 'datetime:F j, Y',
        'end_at'   => 'datetime:F j, Y',
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

    public function user()
    {
        return $this->belongsTo(UserModel::class, 'user_id', 'uid');
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

    public function scopeActiveForUserAndDeviceType(Builder $query, $userId, string $deviceType): Builder
    {
        return $query->where('user_id', $userId)
            ->currentlyActive()
            ->whereHas('plan', function (Builder $planQuery) use ($deviceType) {
                $planQuery->where('device_type', strtolower(trim($deviceType)));
            });
    }

    public function scopeCurrentlyActive(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->whereNotNull('end_at')
            ->where('end_at', '>=', Carbon::now()->startOfDay());
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
               ! self::endAtIsExpired($this->end_at);
    }

    public static function endAtIsExpired($endAt): bool
    {
        if (! $endAt) {
            return true;
        }

        return Carbon::parse($endAt)->endOfDay()->isPast();
    }

    public static function endAtForDuration(Carbon $startAt, int $durationDays): Carbon
    {
        $days = max($durationDays, 1);

        return $startAt->copy()
            ->startOfDay()
            ->addDays($days - 1)
            ->endOfDay();
    }

    public static function renewEndAt(?Carbon $currentEndAt, Carbon $startAt, int $durationDays): Carbon
    {
        $days = max($durationDays, 1);

        if ($currentEndAt && ! self::endAtIsExpired($currentEndAt)) {
            return $currentEndAt->copy()
                ->addDays($days)
                ->endOfDay();
        }

        return self::endAtForDuration($startAt, $days);
    }

    public function extend(): void
    {
        if (!$this->plan) {
            return;
        }

        $newExpiry = self::renewEndAt(
            $this->end_at,
            Carbon::now(),
            $this->plan->duration_days
        );

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
