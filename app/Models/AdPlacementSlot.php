<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AdPlacementSlot extends Model
{
    protected $fillable = ['code', 'label', 'platform', 'media_type', 'dimensions', 'description', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function rates(): HasMany
    {
        return $this->hasMany(AdBillingRate::class, 'placement_slot_id');
    }
}
