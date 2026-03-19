<?php

namespace App\Models\New;

use Illuminate\Database\Eloquent\Model;

class Reel extends Model
{
    protected $table = 'reels';

    protected $fillable = [
        'user_id',
        'video_url',
        'thumbnail_url',
        'caption',
        'duration_ms',
        'category',
        'language',
        'status'
    ];

    // 🔗 Relationships

    public function likes()
    {
        return $this->hasMany(ReelLike::class, 'reel_id');
    }

    public function comments()
    {
        return $this->hasMany(ReelComment::class, 'reel_id');
    }

    public function views()
    {
        return $this->hasMany(ReelView::class, 'reel_id');
    }
}
