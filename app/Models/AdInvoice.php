<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AdInvoice extends Model
{
    protected $fillable = ['invoice_no', 'advertiser_id', 'campaign_id', 'billing_period_start', 'billing_period_end', 'subtotal', 'tax_rate', 'tax', 'total', 'paid_amount', 'currency', 'status', 'due_at', 'paid_at'];

    protected $casts = ['subtotal' => 'decimal:2', 'tax_rate' => 'decimal:3', 'tax' => 'decimal:2', 'total' => 'decimal:2', 'paid_amount' => 'decimal:2', 'billing_period_start' => 'date', 'billing_period_end' => 'date', 'due_at' => 'datetime', 'paid_at' => 'datetime'];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(AdCampaign::class, 'campaign_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(AdPayment::class, 'invoice_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(AdInvoiceItem::class, 'invoice_id');
    }
}
