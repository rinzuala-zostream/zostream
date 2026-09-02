<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdCreative extends Model
{
    protected $fillable = ['campaign_id', 'name', 'type', 'media_url', 'thumbnail_url', 'target_url', 'duration_seconds', 'skip_after_seconds', 'is_skippable', 'existing_ad_num', 'is_active'];

    protected $casts = ['is_skippable' => 'boolean', 'is_active' => 'boolean', 'duration_seconds' => 'integer', 'skip_after_seconds' => 'integer', 'existing_ad_num' => 'integer'];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(AdCampaign::class, 'campaign_id');
    }
}
