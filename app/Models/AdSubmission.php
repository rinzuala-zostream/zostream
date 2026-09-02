<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AdSubmission extends Model
{
    public const STATUS_PENDING = 'pending_review';

    public const STATUS_CHANGES_REQUESTED = 'changes_requested';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'reference_no',
        'public_token_hash',
        'status',
        'business_name',
        'contact_name',
        'contact_phone',
        'contact_email',
        'ads_name',
        'description',
        'type',
        'placement_code',
        'billing_model',
        'target_quantity',
        'quoted_rate',
        'quoted_amount',
        'currency',
        'daily_budget',
        'media_url',
        'destination_url',
        'requested_start_date',
        'requested_period_days',
        'review_note',
        'rejection_reason',
        'reviewed_by',
        'reviewed_at',
        'approved_ad_num',
        'submitted_ip_hash',
    ];

    protected $hidden = [
        'public_token_hash',
        'submitted_ip_hash',
    ];

    protected $casts = [
        'requested_start_date' => 'date:Y-m-d',
        'requested_period_days' => 'integer',
        'target_quantity' => 'integer',
        'quoted_rate' => 'decimal:4',
        'quoted_amount' => 'decimal:2',
        'daily_budget' => 'decimal:2',
        'reviewed_at' => 'datetime',
        'approved_ad_num' => 'integer',
    ];

    public function assets(): HasMany
    {
        return $this->hasMany(AdSubmissionAsset::class)->orderBy('sort_order');
    }

    public function events(): HasMany
    {
        return $this->hasMany(AdSubmissionEvent::class)->orderByDesc('created_at');
    }

    public function campaign()
    {
        return $this->hasOne(AdCampaign::class, 'submission_id');
    }
}
