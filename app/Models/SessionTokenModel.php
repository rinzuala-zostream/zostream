<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SessionTokenModel extends Model
{
    private const HASH_PREFIX = 'sha256:';

    protected $table = 'session_tokens';
    protected $fillable = [
        'user_id',
        'access_token',
        'refresh_token',
        'access_expires_at',
        'refresh_expires_at',
        'device_name',
        'device_id',
    ];

    protected $casts = [
        'access_expires_at' => 'datetime',
        'refresh_expires_at' => 'datetime',
    ];

    public static function digest(string $token): string
    {
        return self::HASH_PREFIX.hash('sha256', $token);
    }

    public static function findByAccessToken(string $token): ?self
    {
        return self::where('access_token', self::digest($token))
            // Transitional compatibility for sessions issued before hashed
            // token storage was introduced.
            ->orWhere(function ($query) use ($token) {
                $query->where('access_token', $token)
                    ->where('access_token', 'not like', self::HASH_PREFIX.'%');
            })
            ->first();
    }

    public static function findByRefreshToken(string $token): ?self
    {
        return self::where('refresh_token', self::digest($token))
            ->orWhere(function ($query) use ($token) {
                $query->where('refresh_token', $token)
                    ->where('refresh_token', 'not like', self::HASH_PREFIX.'%');
            })
            ->first();
    }
}
