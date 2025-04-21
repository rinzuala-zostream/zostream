<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BrowserSubscriptionModel extends Model
{
    protected $table = 'browser_subscription'; // Update to your actual table name

    protected $primaryKey = 'num';
    public $incrementing = true;
    public $timestamps = false;

    protected $fillable = [
        'id',
        'plan',
        'period',
        'create_date',
    ];
}
