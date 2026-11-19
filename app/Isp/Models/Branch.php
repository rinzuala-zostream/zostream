<?php

namespace App\Isp\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Branch extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'router_id', 'operator_percentage', 'ott_deduction', 'is_active', 'billing_type', 'bandwidth_mbps', 'bandwidth_rate', 'bandwidth_gst', 'bandwidth_due_day', 'bandwidth_started_at'];

    protected function casts(): array
    {
        return [
            'bandwidth_mbps' => 'decimal:2',
            'bandwidth_rate' => 'decimal:2',
            'bandwidth_gst' => 'decimal:2',
            'bandwidth_due_day' => 'integer',
            'bandwidth_started_at' => 'date',
            'operator_percentage' => 'decimal:2',
            'ott_deduction' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function isBandwidthOnly(): bool
    {
        return $this->billing_type === 'bandwidth_only';
    }

    public function bandwidthInvoices(): HasMany
    {
        return $this->hasMany(BandwidthInvoice::class);
    }

    public function hasOverdueBandwidthInvoice(): bool
    {
        return $this->isBandwidthOnly() && $this->bandwidthInvoices()
            ->whereNull('paid_at')->whereDate('due_date', '<', today())->exists();
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function packages(): BelongsToMany
    {
        return $this->belongsToMany(Package::class)->withTimestamps();
    }

    public function router(): BelongsTo
    {
        return $this->belongsTo(Router::class);
    }
}
