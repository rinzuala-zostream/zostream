<?php

namespace App\Services;

use App\Models\AdBillingRate;
use App\Models\AdPlacementSlot;
use Illuminate\Validation\ValidationException;

class AdPricingService
{
    public function catalogue(): array
    {
        return AdPlacementSlot::query()
            ->where('is_active', true)
            ->with(['rates' => fn ($query) => $query->where('is_active', true)->orderBy('billing_model')])
            ->orderBy('id')
            ->get()
            ->map(fn (AdPlacementSlot $slot) => [
                'code' => $slot->code,
                'label' => $slot->label,
                'platform' => $slot->platform,
                'media_type' => $slot->media_type,
                'dimensions' => $slot->dimensions,
                'description' => $slot->description,
                'rates' => $slot->rates->map(fn (AdBillingRate $rate) => [
                    'billing_model' => $rate->billing_model,
                    'rate' => (float) $rate->rate,
                    'minimum_charge' => (float) $rate->minimum_charge,
                    'currency' => $rate->currency,
                    'requires_prepayment' => $rate->requires_prepayment,
                    'unit_label' => $this->unitLabel($rate->billing_model),
                ])->values(),
            ])->all();
    }

    public function quote(array $input): array
    {
        $billingModel = strtoupper((string) $input['billing_model']);
        $mediaType = $input['type'] === 'video' ? 'video' : 'image';
        $slot = AdPlacementSlot::query()
            ->where('code', $input['placement_code'])
            ->where('media_type', $mediaType)
            ->where('is_active', true)
            ->first();

        if (! $slot) {
            throw ValidationException::withMessages([
                'placement_code' => ['The selected placement is not available for this ad type.'],
            ]);
        }

        $rate = AdBillingRate::query()
            ->where('placement_slot_id', $slot->id)
            ->where('billing_model', $billingModel)
            ->where('is_active', true)
            ->first();

        if (! $rate) {
            throw ValidationException::withMessages([
                'billing_model' => ['The selected billing model is not available for this placement.'],
            ]);
        }

        if ($billingModel !== 'FLAT' && (int) ($input['target_quantity'] ?? 0) < 1) {
            throw ValidationException::withMessages([
                'target_quantity' => ['A target quantity is required for CPM, CPC and CPV pricing.'],
            ]);
        }

        $periodDays = max(1, (int) $input['requested_period_days']);
        $targetQuantity = $billingModel === 'FLAT' ? $periodDays : (int) $input['target_quantity'];
        $billableUnits = match ($billingModel) {
            'CPM' => $targetQuantity / 1000,
            default => $targetQuantity,
        };
        $amount = max((float) $rate->minimum_charge, round($billableUnits * (float) $rate->rate, 2));

        return [
            'placement_slot_id' => $slot->id,
            'placement_code' => $slot->code,
            'placement_label' => $slot->label,
            'billing_model' => $billingModel,
            'target_quantity' => $billingModel === 'FLAT' ? null : $targetQuantity,
            'billing_quantity' => $billableUnits,
            'rate' => (float) $rate->rate,
            'minimum_charge' => (float) $rate->minimum_charge,
            'amount' => $amount,
            'currency' => $rate->currency,
            'requires_prepayment' => $rate->requires_prepayment,
            'unit_label' => $this->unitLabel($billingModel),
        ];
    }

    private function unitLabel(string $model): string
    {
        return match ($model) {
            'CPM' => 'per 1,000 impressions',
            'CPC' => 'per valid click',
            'CPV' => 'per valid video view',
            default => 'per day',
        };
    }
}
