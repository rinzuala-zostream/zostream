<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdBillingRate extends Model
{
    protected $fillable = ['placement_slot_id', 'billing_model', 'rate', 'minimum_charge', 'currency', 'requires_prepayment', 'is_active'];

    protected $casts = [
        'rate' => 'decimal:4',
        'minimum_charge' => 'decimal:2',
        'requires_prepayment' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function placement(): BelongsTo
    {
        return $this->belongsTo(AdPlacementSlot::class, 'placement_slot_id');
    }
}
