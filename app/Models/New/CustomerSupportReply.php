<?php

namespace App\Models\New;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CustomerSupportReply extends Model
{
    use HasFactory;

    protected $table = 'customer_support_replies';

    public $timestamps = false;

    protected $fillable = [
        'support_id',
        'admin_id',
        'reply',
    ];

    protected $casts = [
        'admin_id' => 'integer',
        'created_at' => 'datetime',
    ];

    public function support()
    {
        return $this->belongsTo(CustomerSupport::class, 'support_id', 'id');
    }

    public function admin()
    {
        return $this->belongsTo(AdminUser::class, 'admin_id', 'id');
    }
}
