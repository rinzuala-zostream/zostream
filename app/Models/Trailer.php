<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Trailer extends Model
{
    protected $fillable = [
        'movie_id',
        'url',
    ];

    public function movie()
    {
        return $this->belongsTo(MovieModel::class, 'movie_id', 'num');
    }
}
