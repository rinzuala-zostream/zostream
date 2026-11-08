<?php

namespace App\Models\New;

use App\Models\UserModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CustomerSupport extends Model
{
    use HasFactory;

    protected $table = 'customer_support';

    protected $fillable = [
        'user_id',
        'complaint',
        'status',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'status' => 'string',
    ];

    public function user()
    {
        return $this->belongsTo(UserModel::class, 'user_id', 'uid');
    }

    public function replies()
    {
        return $this->hasMany(CustomerSupportReply::class, 'support_id', 'id');
    }

    public function latestReply()
    {
        return $this->hasOne(CustomerSupportReply::class, 'support_id', 'id')->latestOfMany('created_at');
    }
}
