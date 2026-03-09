<?php

namespace App\Models\New;

use App\Models\EpisodeModel;
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
        'season_number',
        'title',
        'description',
        'poster',
        'release_year'
    ];

    public function movie()
    {
        return $this->belongsTo(MovieModel::class, 'movie_id', 'num');
    }

    public function episodes()
    {
        return $this->hasMany(EpisodeModel::class, 'season_id', 'num');
    }
}
