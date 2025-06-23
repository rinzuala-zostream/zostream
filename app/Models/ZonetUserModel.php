<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ZonetUserModel extends Model
{
    use HasFactory;

    protected $table = 'zonet_users';

    protected $primaryKey = 'num';

    public $incrementing = true;
    protected $keyType = 'int';

    protected $fillable = [
        'id', // user.uid
        'created_at',
    ];

    public $timestamps = false;

    // Relationship: one user has many subscriptions
    public function subscriptions()
    {
        return $this->hasMany(ZonetSubscriptionModel::class, 'user_num', 'num');
    }
}
