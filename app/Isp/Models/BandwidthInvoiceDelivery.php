<?php

namespace App\Isp\Models;

use Illuminate\Database\Eloquent\Model;

class BandwidthInvoiceDelivery extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer', 'processing_at' => 'datetime',
            'next_attempt_at' => 'datetime', 'sent_at' => 'datetime',
        ];
    }
}
