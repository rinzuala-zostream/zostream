<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EpisodeModel extends Model
{
    protected $table = 'episode'; // adjust if table name is different

    protected $primaryKey = 'num';
    public $incrementing = true;
    protected $keyType = 'int';

    public $timestamps = false;

    protected $fillable = [
        'desc',
        'id',
        'img',
        'isProtected',
        'isPPV',
        'isPremium',
        'season_id',
        'ppv_amount',
        'title',
        'txt',
        'url',
        'dash_url',
        'hls_url',
        'views',
        'isEnable',
        'token',
        'status',
        'create_date',
        'movie_id',
    ];

    protected $casts = [
        'isProtected' => 'boolean',
        'isPPV' => 'boolean',
        'isPremium' => 'boolean',
        'isEnable' => 'boolean',
        'views' => 'integer',
    ];

    // app/Models/EpisodeModel.php

    public function movie()
    {
        return $this->belongsTo(MovieModel::class, 'movie_id');
    }
}
