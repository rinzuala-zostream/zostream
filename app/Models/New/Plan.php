<?php

namespace App\Models\New;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    use HasFactory;

    protected $table = 'n_plans';

    protected $fillable = [
        'name',
        'price',
        'duration_days',
        'device_limit_mobile',
        'device_limit_browser',
        'device_limit_tv',
        'quality',
        'is_active',
    ];

    public function subscriptions()
    {
        return $this->hasMany(Subscription::class, 'plan_id');
    }
}
