<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ZonetOperator extends Model
{
    protected $table = 'zonet_operators';

    protected $primaryKey = 'num';

    public $timestamps = false; // since no `updated_at` or `created_at` management

    protected $fillable = [
        'id',
        'password',
        'name',
        'phone',
        'address',
        'wallet',
        'created_at',
    ];
}
