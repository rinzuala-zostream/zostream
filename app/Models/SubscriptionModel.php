<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscriptionModel extends Model
{
    protected $primaryKey = 'num'; // Ensure 'num' is set as the primary key

    protected $keyType = 'int'; // Ensure primary key is integer
    public $incrementing = true;

    protected $table = 'plan_subscription';

    protected $fillable = ['id', 'sub_plan', 'period', 'create_date'];
    public $timestamps = false; //
}
