<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WatchHistoryModel extends Model
{
    protected $table = 'watch_position';
    protected $primaryKey = 'num';

    public $incrementing = true;
    protected $keyType = 'int';

    public $timestamps = false;

    // Mass assignable fields (optional)
    protected $fillable = [
        'movie_id',
        'movie_type',
        'position',
        'user_id',
    ];
}
