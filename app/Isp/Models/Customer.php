<?php

namespace App\Isp\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Customer extends Model
{
    use HasFactory;

    protected $fillable = ['router_id', 'package_id', 'branch_id', 'name', 'phone', 'address', 'router_device_condition', 'aadhaar_front_path', 'aadhaar_back_path', 'aadhaar_qr_verified_at', 'username', 'password', 'status', 'expires_at', 'wifi_reminder_sent_for', 'expiry_suspended_for', 'mikrotik_id', 'last_synced_at'];

    protected $hidden = ['password', 'aadhaar_front_path', 'aadhaar_back_path'];

    protected function casts(): array
    {
        return ['password' => 'encrypted', 'expires_at' => 'date', 'wifi_reminder_sent_for' => 'date', 'expiry_suspended_for' => 'date', 'aadhaar_qr_verified_at' => 'datetime', 'last_synced_at' => 'datetime'];
    }

    public function router(): BelongsTo
    {
        return $this->belongsTo(Router::class);
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function routerPayment(): HasOne
    {
        return $this->hasOne(CustomerRouterPayment::class);
    }
}
