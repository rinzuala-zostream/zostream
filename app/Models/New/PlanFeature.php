<?php

namespace App\Models\New;

use Illuminate\Database\Eloquent\Model;

class PlanFeature extends Model
{
    protected $table = 'n_plan_features';

    protected $fillable = [
        'plan_name',
        'feature',
        'sort_order',
        'is_active'
    ];
}