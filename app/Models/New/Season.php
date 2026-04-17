<?php

namespace App\Models\New;
use App\Models\MovieModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Season extends Model
{
    use HasFactory;

    protected $table = 'seasons';
    protected $primaryKey = 'num';

    protected $fillable = [
        'id',
        'movie_id',
        'isPayPerView',
        'amount',
        'season_number',
        'title',
        'description',
        'poster',
        'release_year',
        'status',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'season_number' => 'integer',
        'release_year' => 'integer',
        'isPayPerView' => 'boolean'
    ];

    public function movie()
    {
        return $this->belongsTo(MovieModel::class, 'movie_id', 'num');
    }

    public function episodes()
    {
        return $this->hasMany(Episode::class, 'season_id', 'id');
    }
}
