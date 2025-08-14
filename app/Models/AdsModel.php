<?php

// app/Models/AdsModel.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdsModel extends Model
{
    protected $table = 'ads';
    protected $primaryKey = 'num';
    public $incrementing = true;
    protected $keyType = 'int';
    public $timestamps = false;

    protected $fillable = [
        'ads_name',
        'create_date',
        'description',
        'period',
        'type',
        'video_url',
        'ads_url',
        'feature_img',
        'img1',
        'img2',
        'img3',
        'img4',
    ];

    protected $casts = [
        'period'      => 'integer',
    ];
}
