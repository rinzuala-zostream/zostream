<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PPVPaymentModel extends Model
{
    protected $table = 'ppv_payment'; // Change if your table has a different name

    protected $primaryKey = 'id';

    public $timestamps = true; // created_at and updated_at will be auto-managed

    protected $fillable = [
        'user_id',
        'payment_id',
        'platform',
        'movie_id',
        'rental_period',
        'purchase_date',
        'amount_paid',
        'payment_status',
    ];

    protected $casts = [
        'rental_period' => 'integer',
        'purchase_date' => 'datetime',
        'amount_paid' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
