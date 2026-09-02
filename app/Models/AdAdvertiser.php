<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdAdvertiser extends Model
{
    protected $table = 'ad_advertisers';

    protected $fillable = ['business_name', 'contact_name', 'phone', 'email', 'billing_address', 'tax_id', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];
}
