<?php

namespace App\Isp\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerOnboarding extends Model
{
    protected $fillable = [
        'operator_id',
        'customer_payload',
        'username',
        'aadhaar_front_path',
        'aadhaar_back_path',
        'aadhaar_qr_verified_at',
        'router_amount',
        'notes',
        'cashfree_order_id',
        'payment_session_id',
        'gateway_payment_id',
        'status',
        'customer_id',
        'activation_error',
        'sync_error',
        'completed_at',
        'expires_at',
    ];

    protected $hidden = ['customer_payload', 'payment_session_id'];

    protected function casts(): array
    {
        return [
            'customer_payload' => 'encrypted:array',
            'router_amount' => 'decimal:2',
            'aadhaar_qr_verified_at' => 'datetime',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_id');
    }
}
