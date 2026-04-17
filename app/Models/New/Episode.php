<?php

namespace App\Models\New;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Episode extends Model
{
    use HasFactory;

    protected $table = 'episodes';
    protected $primaryKey = 'num';

    protected $fillable = [
        'id',
        'season_id',
        'isPayPerView',
        'episode_number',
        'title',
        'description',
        'thumbnail',
        'duration',
        'release_date',
        'is_active',
        'status'
    ];

    protected $casts = [
        'episode_number' => 'integer',
        'duration' => 'integer',
        'release_date' => 'date',
        'is_active' => 'boolean',
        'isPayPerView' => 'boolean',
        'status' => 'string'
    ];

    public function season()
    {
        return $this->belongsTo(Season::class, 'season_id', 'id');
    }
}