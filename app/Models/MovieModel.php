<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MovieModel extends Model
{
    protected $table = 'movie'; // adjust if your table name is different

    protected $primaryKey = 'num'; // Since the primary key is 'num', not 'id'
    public $incrementing = true;   // true if it's AUTO_INCREMENT
    protected $keyType = 'int';    // integer primary key

    public $timestamps = false;    // 'create_date' is not standard 'created_at'

    protected $casts = [
        'isProtected' => 'boolean',
        'isBollywood' => 'boolean',
        'isCompleted' => 'boolean',
        'isDocumentary' => 'boolean',
        'isAgeRestricted' => 'boolean',
        'isDubbed' => 'boolean',
        'isEnable' => 'boolean',
        'isHollywood' => 'boolean',
        'isKorean' => 'boolean',
        'isMizo' => 'boolean',
        'isPayPerView' => 'boolean',
        'isPremium' => 'boolean',
        'isSeason' => 'boolean',
        'isSubtitle' => 'boolean',
    ];

    protected $fillable = [
        'cover_img',
        'create_date',
        'description',
        'director',
        'duration',
        'genre',
        'id',
        'isProtected',
        'isBollywood',
        'isCompleted',
        'isDocumentary',
        'isAgeRestricted',
        'isDubbed',
        'isEnable',
        'isHollywood',
        'isKorean',
        'isMizo',
        'isPayPerView',
        'isPremium',
        'isSeason',
        'isSubtitle',
        'subtitle',
        'poster',
        'release_on',
        'title',
        'url',
        'dash_url',
        'hls_url',
        'trailer',
        'views',
        'token'
    ];
}
