<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdSubmissionEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'action',
        'from_status',
        'to_status',
        'note',
        'actor_type',
        'actor_id',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function submission(): BelongsTo
    {
        return $this->belongsTo(AdSubmission::class, 'ad_submission_id');
    }
}
