<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WistListModel extends Model
{
    protected $table = 'wist_list';
    protected $primaryKey = 'id';

    public $incrementing = true;
    protected $keyType = 'int';

    public $timestamps = false;

    protected $fillable = [
        'uid',
        'movie_id',
        'poster',
        'title',
        'create_date',
    ];
}
