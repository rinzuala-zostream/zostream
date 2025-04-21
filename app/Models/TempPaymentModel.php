<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class TempPaymentModel extends Model
{
    protected $table = 'temp_payments'; // Change if your table name is different

    protected $primaryKey = 'id';

    public $timestamps = false; // We’ll use `created_at` manually, not Laravel's timestamps

    protected $fillable = [
        'user_id',
        'user_mail',
        'amount',
        'transaction_id',
        'subscription_period',
        'created_at',
        'device_type',
        'payment_type',
        'content_id',
        'total_pay',
        'paln'
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'amount' => 'integer',
        'subscription_period' => 'integer',
        'total_pay' => 'integer',
    ];
}
