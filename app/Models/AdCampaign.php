<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AdCampaign extends Model
{
    protected $fillable = [
        'advertiser_id', 'submission_id', 'name', 'billing_model', 'rate', 'requires_prepayment',
        'target_quantity', 'consumed_quantity', 'estimated_amount', 'accrued_amount',
        'daily_budget', 'currency', 'start_at', 'end_at', 'status', 'activated_at', 'completed_at',
    ];

    protected $casts = [
        'rate' => 'decimal:4', 'estimated_amount' => 'decimal:2', 'accrued_amount' => 'decimal:4',
        'daily_budget' => 'decimal:2', 'target_quantity' => 'integer', 'consumed_quantity' => 'integer',
        'requires_prepayment' => 'boolean',
        'start_at' => 'datetime', 'end_at' => 'datetime', 'activated_at' => 'datetime', 'completed_at' => 'datetime',
    ];

    public function advertiser(): BelongsTo
    {
        return $this->belongsTo(AdAdvertiser::class, 'advertiser_id');
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(AdSubmission::class, 'submission_id');
    }

    public function creatives(): HasMany
    {
        return $this->hasMany(AdCreative::class, 'campaign_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(AdInvoice::class, 'campaign_id');
    }
}
