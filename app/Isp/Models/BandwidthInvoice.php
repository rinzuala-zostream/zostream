<?php

namespace App\Isp\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BandwidthInvoice extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'billing_month' => 'date', 'issued_on' => 'date', 'due_date' => 'date', 'paid_at' => 'datetime',
            'bandwidth_mbps' => 'decimal:2', 'rate' => 'decimal:2', 'gst_percentage' => 'decimal:2',
            'subtotal' => 'decimal:2', 'gst_amount' => 'decimal:2', 'total' => 'decimal:2',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function isOverdue(): bool
    {
        return $this->paid_at === null && $this->due_date->lt(today());
    }

    public function number(): string
    {
        return 'BW-'.$this->billing_month->format('Ym').'-'.$this->id;
    }
}
