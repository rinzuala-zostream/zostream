<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class QrAuthSession extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CANCELLED = 'cancelled';

    public const AUTH_METHOD_OTP = 'otp';
    public const AUTH_METHOD_PASSWORD = 'password';

    protected $table = 'qr_auth_sessions';

    protected $fillable = [
        'session_token',
        'channel_code',
        'device_id',
        'device_name',
        'device_type',
        'status',
        'user_id',
        'auth_method',
        'expires_at',
        'approved_at',
        'completed_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'approved_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->whereIn('status', [self::STATUS_PENDING, self::STATUS_APPROVED])
            ->where('expires_at', '>', now());
    }

    public function scopeExpired(Builder $query): Builder
    {
        return $query->where('expires_at', '<=', now());
    }

    public function isExpired(): bool
    {
        return $this->expires_at instanceof CarbonInterface
            ? $this->expires_at->isPast()
            : now()->greaterThan($this->expires_at);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function markApproved(string $userId, string $authMethod): bool
    {
        return $this->update([
            'status' => self::STATUS_APPROVED,
            'user_id' => $userId,
            'auth_method' => $authMethod,
            'approved_at' => now(),
        ]);
    }

    public function markCompleted(): bool
    {
        return $this->update([
            'status' => self::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);
    }

    public function markExpired(): bool
    {
        return $this->update([
            'status' => self::STATUS_EXPIRED,
        ]);
    }

    public function markCancelled(): bool
    {
        return $this->update([
            'status' => self::STATUS_CANCELLED,
        ]);
    }
}
