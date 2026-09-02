<?php

namespace App\Http\Controllers\Api\V4;

use App\Http\Controllers\Controller;
use App\Models\AdBillingRate;
use App\Models\AdCampaign;
use App\Models\AdInvoice;
use App\Models\AdPlacementSlot;
use App\Models\AdsModel;
use App\Services\AdBillingService;
use App\Support\Api\V4Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminAdBillingController extends Controller
{
    public function __construct(private readonly AdBillingService $billing) {}

    public function rates()
    {
        return V4Response::success(
            AdPlacementSlot::query()->with(['rates' => fn ($query) => $query->orderBy('billing_model')])->orderBy('id')->get()
        );
    }

    public function updateRate(Request $request, AdBillingRate $rate)
    {
        $data = $request->validate([
            'rate' => ['required', 'numeric', 'min:0', 'max:100000000'],
            'minimum_charge' => ['required', 'numeric', 'min:0', 'max:100000000'],
            'currency' => ['required', 'string', 'size:3'],
            'requires_prepayment' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
        ]);
        $data['currency'] = strtoupper($data['currency']);
        $rate->update($data);

        return V4Response::success($rate->fresh('placement'), 'Billing rate updated.');
    }

    public function updatePlacement(Request $request, AdPlacementSlot $placement)
    {
        $data = $request->validate([
            'label' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $placement->update($data);

        return V4Response::success($placement->fresh('rates'), 'Placement updated.');
    }

    public function dashboard(Request $request)
    {
        $data = $request->validate([
            'status' => ['nullable', 'string', 'max:32'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $campaigns = AdCampaign::query()
            ->with(['advertiser', 'submission', 'creatives', 'invoices.payments'])
            ->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->latest()
            ->paginate(20);

        $items = collect($campaigns->items())->map(function (AdCampaign $campaign) {
            $campaign->setAttribute('impressions_count', DB::table('ad_impressions')->where('campaign_id', $campaign->id)->count());
            $campaign->setAttribute('clicks_count', DB::table('ad_clicks')->where('campaign_id', $campaign->id)->count());
            $campaign->setAttribute('valid_views_count', DB::table('ad_billing_events')->where('campaign_id', $campaign->id)->where('event_type', 'video_view')->count());

            return $campaign;
        });

        return V4Response::success([
            'summary' => [
                'pending_payment' => AdCampaign::where('status', 'pending_payment')->count(),
                'active' => AdCampaign::where('status', 'active')->count(),
                'completed' => AdCampaign::where('status', 'completed')->count(),
                'invoiced' => (float) AdInvoice::sum('total'),
                'paid' => (float) AdInvoice::sum('paid_amount'),
                'outstanding' => (float) AdInvoice::whereIn('status', ['pending', 'partial'])
                    ->selectRaw('COALESCE(SUM(total - paid_amount), 0) AS outstanding')
                    ->value('outstanding'),
                'impressions' => DB::table('ad_impressions')->count(),
                'clicks' => DB::table('ad_clicks')->count(),
                'valid_views' => DB::table('ad_billing_events')->where('event_type', 'video_view')->count(),
            ],
            'items' => $items,
            'pagination' => [
                'current_page' => $campaigns->currentPage(),
                'last_page' => $campaigns->lastPage(),
                'total' => $campaigns->total(),
            ],
        ]);
    }

    public function markInvoicePaid(Request $request, AdInvoice $invoice)
    {
        $data = $request->validate([
            'amount' => ['nullable', 'numeric', 'min:0.01'],
            'payment_method' => ['required', Rule::in(['cash', 'bank_transfer', 'upi', 'manual', 'razorpay'])],
            'reference' => ['nullable', 'string', 'max:255'],
        ]);
        $updated = $this->billing->markPaid($invoice, [
            'amount' => $data['amount'] ?? $invoice->total,
            'payment_method' => $data['payment_method'],
            'gateway' => 'manual',
            'gateway_order_id' => $data['reference'] ?? 'manual-'.$invoice->id.'-'.now()->timestamp,
            'metadata' => ['recorded_by' => (string) $request->input('auth_user_id')],
        ]);

        return V4Response::success($updated, 'Invoice marked as paid and campaign activated.');
    }

    public function updateCampaignStatus(Request $request, AdCampaign $campaign)
    {
        $data = $request->validate(['status' => ['required', Rule::in(['active', 'paused', 'completed', 'cancelled'])]]);
        if (in_array($campaign->status, ['completed', 'cancelled'], true) && $data['status'] !== $campaign->status) {
            return V4Response::error('AD_CAMPAIGN_FINAL', 'A completed or cancelled campaign cannot be reopened.', 409);
        }
        if ($data['status'] === 'active' && $campaign->requires_prepayment && ! $campaign->invoices()->where('status', 'paid')->exists()) {
            return V4Response::error('AD_PAYMENT_REQUIRED', 'The campaign invoice must be paid before activation.', 409);
        }
        $campaign->update([
            'status' => $data['status'],
            'completed_at' => $data['status'] === 'completed' ? now() : $campaign->completed_at,
        ]);
        AdsModel::where('campaign_id', $campaign->id)->update(['is_active' => $data['status'] === 'active']);

        return V4Response::success($campaign->fresh(), 'Campaign status updated.');
    }
}
