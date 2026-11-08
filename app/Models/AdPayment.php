<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdPayment extends Model
{
    protected $fillable = ['advertiser_id', 'invoice_id', 'amount', 'currency', 'payment_method', 'gateway', 'gateway_order_id', 'gateway_payment_id', 'status', 'metadata', 'paid_at'];

    protected $casts = ['amount' => 'decimal:2', 'metadata' => 'array', 'paid_at' => 'datetime'];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(AdInvoice::class, 'invoice_id');
    }
}
