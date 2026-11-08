<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdInvoiceItem extends Model
{
    protected $fillable = ['invoice_id', 'campaign_id', 'description', 'billing_model', 'quantity', 'rate', 'amount'];

    protected $casts = ['quantity' => 'decimal:4', 'rate' => 'decimal:4', 'amount' => 'decimal:2'];
}
