<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WistListModel extends Model
{
    protected $table = 'wist_list';

    protected $primaryKey = 'id';

    public $timestamps = true;

    protected $fillable = [
        'uid',
        'movie_id',
        'title',
        'cover',
        'poster',
    ];

    protected $casts = [
        'id' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function movie()
{
    return $this->belongsTo(MovieModel::class, 'movie_id', 'id');
}
}

