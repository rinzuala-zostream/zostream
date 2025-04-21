<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TVSubscriptionModel extends Model
{
    protected $table = 'tv_subscription'; // Update if your table has a different name

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
