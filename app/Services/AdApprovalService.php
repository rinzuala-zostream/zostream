<?php

namespace App\Services;

use App\Models\AdAdvertiser;
use App\Models\AdBillingRate;
use App\Models\AdCampaign;
use App\Models\AdInvoice;
use App\Models\AdPlacementSlot;
use App\Models\AdSubmission;
use Carbon\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdApprovalService
{
    public function approve(AdSubmission $submission, array $overrides, string $adminId): AdSubmission
    {
        $approved = DB::transaction(function () use ($submission, $overrides, $adminId) {
            $locked = AdSubmission::query()->lockForUpdate()->findOrFail($submission->getKey());

            if ($locked->status !== AdSubmission::STATUS_PENDING) {
                throw ValidationException::withMessages([
                    'status' => ['Only a pending submission can be approved.'],
                ]);
            }

            $locked->load('assets');
            $startDate = Carbon::parse(
                $overrides['start_date'] ?? $locked->requested_start_date ?? now()
            );
            $period = (int) ($overrides['period_days'] ?? $locked->requested_period_days);
            $feature = $locked->assets->firstWhere('kind', 'feature');
            $mainMediaUrl = $overrides['media_url'] ?? $locked->media_url;
            $slot = AdPlacementSlot::where('code', $locked->placement_code)->where('is_active', true)->firstOrFail();
            $rateConfig = AdBillingRate::where('placement_slot_id', $slot->id)
                ->where('billing_model', $locked->billing_model)
                ->firstOrFail();
            $billingQuantity = match ($locked->billing_model) {
                'FLAT' => $period,
                'CPM' => ((int) $locked->target_quantity) / 1000,
                default => (int) $locked->target_quantity,
            };
            $amount = max((float) $rateConfig->minimum_charge, round($billingQuantity * (float) $locked->quoted_rate, 2));
            if (blank($locked->public_token_encrypted)) {
                $publicToken = Str::random(48);
                $locked->forceFill([
                    'public_token_hash' => hash('sha256', $publicToken),
                    'public_token_encrypted' => Crypt::encryptString($publicToken),
                ])->save();
            }

            $advertiserQuery = AdAdvertiser::query();
            if ($locked->contact_email) {
                $advertiserQuery->where('email', $locked->contact_email);
            } else {
                $advertiserQuery->where('phone', $locked->contact_phone);
            }
            $advertiser = $advertiserQuery->first() ?: new AdAdvertiser;
            $advertiser->fill([
                'business_name' => $locked->business_name,
                'contact_name' => $locked->contact_name,
                'phone' => $locked->contact_phone,
                'email' => $locked->contact_email,
                'is_active' => true,
            ])->save();

            $campaign = AdCampaign::create([
                'advertiser_id' => $advertiser->id,
                'submission_id' => $locked->id,
                'name' => $locked->ads_name,
                'billing_model' => $locked->billing_model,
                'rate' => $locked->quoted_rate,
                'requires_prepayment' => true,
                'target_quantity' => $locked->target_quantity,
                'estimated_amount' => $amount,
                'daily_budget' => $locked->daily_budget,
                'currency' => $locked->currency,
                'start_at' => $startDate->startOfDay(),
                'end_at' => $startDate->copy()->startOfDay()->addDays($period),
                'status' => 'pending_payment',
            ]);
            $creative = $campaign->creatives()->create([
                'name' => $locked->ads_name,
                'type' => $locked->type,
                'media_url' => $mainMediaUrl,
                'thumbnail_url' => $feature?->file_url ?: ($locked->type !== 'video' ? $mainMediaUrl : null),
                'target_url' => $locked->destination_url,
                'is_skippable' => $locked->type === 'video',
                'skip_after_seconds' => $locked->type === 'video'
                    ? 10
                    : null,
                'is_active' => true,
            ]);
            DB::table('ad_campaign_placements')->insert([
                'campaign_id' => $campaign->id,
                'creative_id' => $creative->id,
                'placement_slot_id' => $slot->id,
                'priority' => (int) ($overrides['priority'] ?? 0),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $invoice = AdInvoice::create([
                'invoice_no' => $this->newInvoiceNumber(),
                'advertiser_id' => $advertiser->id,
                'campaign_id' => $campaign->id,
                'billing_period_start' => $startDate->toDateString(),
                'billing_period_end' => $startDate->copy()->addDays($period)->toDateString(),
                'subtotal' => $amount,
                'tax_rate' => 0,
                'tax' => 0,
                'total' => $amount,
                'currency' => $locked->currency,
                'status' => 'pending',
                'due_at' => now()->addDays(7),
            ]);
            $invoice->items()->create([
                'campaign_id' => $campaign->id,
                'description' => $slot->label.' — '.$locked->billing_model.' campaign',
                'billing_model' => $locked->billing_model,
                'quantity' => $billingQuantity,
                'rate' => $locked->quoted_rate,
                'amount' => $amount,
            ]);

            $locked->update([
                'status' => AdSubmission::STATUS_APPROVED,
                'requested_start_date' => $startDate->toDateString(),
                'requested_period_days' => $period,
                'quoted_amount' => $amount,
                'review_note' => $overrides['review_note'] ?? null,
                'rejection_reason' => null,
                'reviewed_by' => $adminId,
                'reviewed_at' => now(),
            ]);

            $locked->events()->create([
                'action' => 'approved',
                'from_status' => AdSubmission::STATUS_PENDING,
                'to_status' => AdSubmission::STATUS_APPROVED,
                'note' => $overrides['review_note'] ?? null,
                'actor_type' => 'admin',
                'actor_id' => $adminId,
            ]);

            return $locked->fresh(['assets', 'events', 'campaign.creatives', 'campaign.invoices.items']);
        });

        return $approved->load(['assets', 'events', 'campaign.creatives', 'campaign.invoices.items']);
    }

    private function newInvoiceNumber(): string
    {
        do {
            $number = 'ZSA-'.now()->format('Ymd').'-'.Str::upper(Str::random(6));
        } while (AdInvoice::where('invoice_no', $number)->exists());

        return $number;
    }
}
