<?php

namespace App\Models\New;

use Illuminate\Database\Eloquent\Model;

class ReelLike extends Model
{
    protected $table = 'reel_likes';

    public $timestamps = false;

    protected $fillable = [
        'reel_id',
        'user_id',
        'created_at'
    ];
}
