<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscriptionHistoryModel extends Model
{
    protected $table = 'sub_history'; // 🔁 Replace with your actual table name

    protected $primaryKey = 'num'; // Assuming `num` is the primary key

    public $timestamps = false; // No created_at or updated_at columns

    protected $fillable = [
        'amount',
        'hming',
        'mail',
        'phone',
        'platform',
        'pid',
        'plan',
        'plan_end',
        'plan_start',
        'uid'
    ];
}
