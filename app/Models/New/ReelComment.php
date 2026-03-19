<?php

namespace App\Models\New;

use Illuminate\Database\Eloquent\Model;

class ReelComment extends Model
{
    protected $table = 'reel_comments';
    public $timestamps = false;

    protected $fillable = [
        'reel_id',
        'user_id',
        'comment'
    ];
}
