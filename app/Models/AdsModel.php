<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdsModel extends Model
{
    protected $table = 'ads'; // adjust if your table name is different

    protected $primaryKey = 'num'; // Since the primary key is 'num', not 'id'
    public $incrementing = true;   // true if it's AUTO_INCREMENT
    protected $keyType = 'int';    // integer primary key

    public $timestamps = false;    // 'create_date' is not standard 'created_at'

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
}
