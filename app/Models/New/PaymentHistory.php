<?php

namespace App\Models\New;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentHistory extends Model
{
    use HasFactory;

    protected $table = 'n_payment_histories';

    protected $fillable = [
        'subscription_id',
        'user_id',
        'model_id',
        'device_type',
        'app_payment_type',
        'amount',
        'currency',
        'payment_method',
        'payment_gateway',
        'transaction_id',
        'status',
        'payment_type',
        'payment_date',
        'expiry_date',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
        'payment_date' => 'datetime',
        'expiry_date' => 'datetime',
    ];

    public function subscription()
    {
        return $this->belongsTo(Subscription::class, 'subscription_id');
    }
}