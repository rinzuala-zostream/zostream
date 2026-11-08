<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdSubmissionAsset extends Model
{
    protected $fillable = [
        'kind',
        'file_url',
        'storage_path',
        'mime_type',
        'file_size',
        'sort_order',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'sort_order' => 'integer',
    ];

    public function submission(): BelongsTo
    {
        return $this->belongsTo(AdSubmission::class, 'ad_submission_id');
    }
}
