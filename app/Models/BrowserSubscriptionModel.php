<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BrowserSubscriptionModel extends Model
{
    protected $primaryKey = 'num'; // Ensure 'num' is set as the primary key

    protected $keyType = 'int'; // Ensure primary key is integer
    public $incrementing = true;

    protected $table = 'browser_subscription';

    protected $fillable = ['id', 'sub_plan', 'period', 'create_date'];
    public $timestamps = false; //
}
