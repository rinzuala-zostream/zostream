<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ZonetSubscriptionModel extends Model
{
    use HasFactory;

    protected $table = 'zonet_subscriptions';

    protected $primaryKey = 'id';

    public $incrementing = true;
    protected $keyType = 'int';

    protected $fillable = [
        'user_num',
        'sub_plan',
        'period',
        'create_date',
    ];

    public $timestamps = false;

    // Relationship: each subscription belongs to a user
    public function zonetUser()
    {
        return $this->belongsTo(ZonetUserModel::class, 'user_num', 'num');
    }
}
