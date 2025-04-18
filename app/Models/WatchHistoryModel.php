<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WatchHistoryModel extends Model
{
    protected $table = 'watch_position';
    protected $primaryKey = 'num'; // Since the primary key is 'num', not 'id'
    public $incrementing = true;   // true if it's AUTO_INCREMENT
    protected $keyType = 'int';    // integer primary key

    public $timestamps = false;    // 'create_date' is not standard 'created_at'
}
