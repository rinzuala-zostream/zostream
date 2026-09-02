<?php

namespace App\Http\Controllers\Api\V4;

use App\Http\Controllers\Controller;
use App\Support\Api\V4Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdServingController extends Controller
{
    public function serve(Request $request)
    {
        $data = $request->validate([
            'placement' => ['required', 'string', 'max:60'],
            'platform' => ['nullable', 'string', 'max:30'],
        ]);
        $now = now();
        $creative = DB::table('ad_campaign_placements as assignment')
            ->join('ad_campaigns as campaign', 'campaign.id', '=', 'assignment.campaign_id')
            ->join('ad_creatives as creative', 'creative.id', '=', 'assignment.creative_id')
            ->join('ad_placement_slots as slot', 'slot.id', '=', 'assignment.placement_slot_id')
            ->leftJoin('ads as live_ad', 'live_ad.num', '=', 'creative.existing_ad_num')
            ->where('slot.code', $data['placement'])
            ->where('slot.is_active', true)
            ->where('assignment.is_active', true)
            ->where('creative.is_active', true)
            ->where('campaign.status', 'active')
            ->where(fn ($query) => $query->whereNull('campaign.start_at')->orWhere('campaign.start_at', '<=', $now))
            ->where(fn ($query) => $query->whereNull('campaign.end_at')->orWhere('campaign.end_at', '>=', $now))
            ->where(fn ($query) => $query->whereNull('campaign.target_quantity')->orWhereColumn('campaign.consumed_quantity', '<', 'campaign.target_quantity'))
            ->when($data['platform'] ?? null, fn ($query, $platform) => $query->whereIn('slot.platform', ['all', $platform]))
            ->orderByDesc('assignment.priority')
            ->inRandomOrder()
            ->select([
                'campaign.id as campaign_id', 'campaign.billing_model', 'creative.id as creative_id',
                'creative.name', 'creative.type', 'creative.media_url', 'creative.thumbnail_url',
                'creative.target_url', 'creative.duration_seconds', 'creative.skip_after_seconds',
                'creative.is_skippable', 'slot.id as placement_slot_id', 'slot.code as placement',
                'live_ad.ads_url',
            ])
            ->first();

        if (! $creative) {
            return V4Response::success(null, 'No eligible ad is available.');
        }

        $payload = [
            'campaign_id' => $creative->campaign_id,
            'creative_id' => $creative->creative_id,
            'placement_slot_id' => $creative->placement_slot_id,
            'expires_at' => now()->addHours(2)->timestamp,
        ];

        return V4Response::success([
            'campaign_id' => $creative->campaign_id,
            'creative_id' => $creative->creative_id,
            'name' => $creative->name,
            'type' => $creative->type,
            'media_url' => $creative->media_url,
            'thumbnail_url' => $creative->thumbnail_url,
            'target_url' => $creative->target_url,
            'ad_url' => $creative->ads_url,
            'duration_seconds' => $creative->duration_seconds,
            'skip_after_seconds' => $creative->skip_after_seconds,
            'is_skippable' => (bool) $creative->is_skippable,
            'placement' => $creative->placement,
            'tracking_token' => $this->sign($payload),
        ]);
    }

    private function sign(array $payload): string
    {
        $encoded = rtrim(strtr(base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        $signature = hash_hmac('sha256', $encoded, (string) config('app.key'));

        return $encoded.'.'.$signature;
    }
}
