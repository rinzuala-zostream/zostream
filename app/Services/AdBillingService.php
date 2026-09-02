<?php

namespace App\Services;

use App\Models\AdInvoice;
use App\Models\AdPayment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdBillingService
{
    public function __construct(private readonly AdCampaignService $campaigns) {}

    public function markPaid(AdInvoice $invoice, array $paymentData): AdInvoice
    {
        $campaignId = DB::transaction(function () use ($invoice, $paymentData) {
            $locked = AdInvoice::query()->lockForUpdate()->findOrFail($invoice->getKey());
            $amount = (float) ($paymentData['amount'] ?? $locked->total);
            if ($amount + 0.001 < (float) $locked->total) {
                throw ValidationException::withMessages(['amount' => ['Payment amount is less than the invoice total.']]);
            }

            if ($locked->status !== 'paid') {
                AdPayment::updateOrCreate(
                    [
                        'gateway' => $paymentData['gateway'] ?? 'manual',
                        'gateway_order_id' => $paymentData['gateway_order_id'] ?? 'manual-'.$locked->id.'-'.now()->timestamp,
                    ],
                    [
                        'advertiser_id' => $locked->advertiser_id,
                        'invoice_id' => $locked->id,
                        'amount' => $amount,
                        'currency' => $locked->currency,
                        'payment_method' => $paymentData['payment_method'] ?? null,
                        'gateway_payment_id' => $paymentData['gateway_payment_id'] ?? null,
                        'status' => 'paid',
                        'metadata' => $paymentData['metadata'] ?? null,
                        'paid_at' => now(),
                    ]
                );
                $locked->update([
                    'paid_amount' => $amount,
                    'status' => 'paid',
                    'paid_at' => now(),
                ]);
            }

            return $locked->campaign_id;
        });

        $this->campaigns->activate(\App\Models\AdCampaign::findOrFail($campaignId));

        return $invoice->fresh(['items', 'payments', 'campaign.creatives']);
    }
}
