<?php

namespace App\Isp\Services;

use App\Isp\Models\Branch;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BranchBillingSettings
{
    public static function rules(): array
    {
        return [
            'billing_type' => ['sometimes', Rule::in(['standard', 'bandwidth_only'])],
            'bandwidth_mbps' => ['required_if:billing_type,bandwidth_only', 'nullable', 'numeric', 'min:0.01', 'max:9999999.99'],
            'bandwidth_rate' => ['required_if:billing_type,bandwidth_only', 'nullable', 'numeric', 'min:0.01', 'max:999999.99'],
            'bandwidth_gst' => ['required_if:billing_type,bandwidth_only', 'nullable', 'numeric', 'min:0', 'max:100'],
            'bandwidth_due_day' => ['required_if:billing_type,bandwidth_only', 'nullable', 'integer', 'min:1', 'max:31'],
        ];
    }

    public static function prepare(array $data, ?Branch $branch): array
    {
        $type = $data['billing_type'] ?? $branch?->billing_type ?? 'standard';
        if ($branch?->isBandwidthOnly() && $type !== 'bandwidth_only'
            && $branch->bandwidthInvoices()->whereNull('paid_at')->exists()) {
            throw ValidationException::withMessages(['billing_type' => 'Settle all bandwidth invoices before changing the branch type.']);
        }
        if ($type === 'bandwidth_only') {
            $data['operator_percentage'] = null;
            $data['ott_deduction'] = null;
            if (! $branch?->isBandwidthOnly()) {
                $data['bandwidth_started_at'] = today()->toDateString();
            }
        }

        return $data;
    }
}
