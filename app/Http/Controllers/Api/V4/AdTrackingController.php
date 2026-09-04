<?php

namespace App\Http\Controllers\Api\V4;

use App\Http\Controllers\Controller;
use App\Models\AdCampaign;
use App\Models\AdsModel;
use App\Support\Api\V4Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdTrackingController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'tracking_token' => ['required', 'string', 'max:2000'],
            'event_id' => ['required', 'uuid'],
            'event' => ['required', 'in:impression,click,video_start,video_25,video_50,video_75,video_complete,skip'],
            'impression_event_id' => ['nullable', 'uuid'],
            'watched_seconds' => ['nullable', 'integer', 'min:0', 'max:86400'],
        ]);
        $token = $this->verify($data['tracking_token']);

        $result = DB::transaction(function () use ($request, $data, $token) {
            $campaign = AdCampaign::query()->lockForUpdate()->findOrFail($token['campaign_id']);
            if ($campaign->status !== 'active') {
                throw ValidationException::withMessages(['campaign' => ['This campaign is not active.']]);
            }

            $identity = [
                'user_id' => $this->hashIdentity($request->input('auth_user_id')),
                'device_id' => $this->hashIdentity($request->header('Device-Token') ?: $request->input('device_id')),
            ];
            $event = $data['event'];
            $impressionId = isset($data['impression_event_id'])
                ? DB::table('ad_impressions')
                    ->where('event_id', $data['impression_event_id'])
                    ->where('campaign_id', $campaign->id)
                    ->where('creative_id', $token['creative_id'])
                    ->value('id')
                : null;

            if ($event === 'impression') {
                $existing = DB::table('ad_impressions')->where('event_id', $data['event_id'])->first();
                if ($existing) {
                    return ['recorded' => false, 'duplicate' => true, 'impression_id' => $existing->id];
                }
                $sourceId = DB::table('ad_impressions')->insertGetId([
                    'event_id' => $data['event_id'], 'campaign_id' => $campaign->id,
                    'creative_id' => $token['creative_id'], 'placement_slot_id' => $token['placement_slot_id'],
                    'user_id' => $identity['user_id'], 'device_id' => $identity['device_id'],
                    'platform' => $request->header('X-Client-Platform'),
                    'ip_hash' => $this->hashIdentity($request->ip()), 'is_valid' => true, 'created_at' => now(),
                ]);
                $this->bill($campaign, $token['creative_id'], 'impression', 'ad_impressions', $sourceId);

                return ['recorded' => true, 'impression_id' => $sourceId];
            }

            if (! $impressionId) {
                throw ValidationException::withMessages([
                    'impression_event_id' => ['A valid impression event is required.'],
                ]);
            }

            if ($event === 'click') {
                $existing = DB::table('ad_clicks')->where('event_id', $data['event_id'])->first();
                if ($existing) {
                    return ['recorded' => false, 'duplicate' => true];
                }
                $sourceId = DB::table('ad_clicks')->insertGetId([
                    'event_id' => $data['event_id'], 'campaign_id' => $campaign->id,
                    'creative_id' => $token['creative_id'], 'impression_id' => $impressionId,
                    'user_id' => $identity['user_id'], 'device_id' => $identity['device_id'],
                    'is_valid' => true, 'created_at' => now(),
                ]);
                $this->bill($campaign, $token['creative_id'], 'click', 'ad_clicks', $sourceId);

                return ['recorded' => true];
            }

            if (DB::table('ad_video_events')->where('event_id', $data['event_id'])->exists()) {
                return ['recorded' => false, 'duplicate' => true];
            }
            $sourceId = DB::table('ad_video_events')->insertGetId([
                'event_id' => $data['event_id'], 'campaign_id' => $campaign->id,
                'creative_id' => $token['creative_id'], 'impression_id' => $impressionId,
                'event' => $event, 'watched_seconds' => $data['watched_seconds'] ?? 0,
                'is_valid' => true, 'created_at' => now(),
            ]);
            if ($event === 'video_complete' || ($data['watched_seconds'] ?? 0) >= 30) {
                $billingSourceId = $impressionId ?: $sourceId;
                $this->bill($campaign, $token['creative_id'], 'video_view', 'ad_video_views', $billingSourceId);
            }

            return ['recorded' => true];
        });

        return V4Response::success($result, 'Ad event processed.');
    }

    private function bill(AdCampaign $campaign, int $creativeId, string $eventType, string $sourceType, int $sourceId): void
    {
        $matches = ($campaign->billing_model === 'CPM' && $eventType === 'impression')
            || ($campaign->billing_model === 'CPC' && $eventType === 'click')
            || ($campaign->billing_model === 'CPV' && $eventType === 'video_view');
        if (! $matches || DB::table('ad_billing_events')->where(['source_type' => $sourceType, 'source_id' => $sourceId])->exists()) {
            return;
        }

        $amount = $campaign->billing_model === 'CPM' ? (float) $campaign->rate / 1000 : (float) $campaign->rate;
        DB::table('ad_billing_events')->insert([
            'advertiser_id' => $campaign->advertiser_id, 'campaign_id' => $campaign->id,
            'creative_id' => $creativeId, 'event_type' => $eventType, 'source_type' => $sourceType,
            'source_id' => $sourceId, 'quantity' => 1, 'rate' => $campaign->rate,
            'amount' => $amount, 'created_at' => now(),
        ]);
        $campaign->increment('consumed_quantity');
        $campaign->increment('accrued_amount', $amount);
        $campaign->refresh();

        $todaySpend = (float) DB::table('ad_billing_events')
            ->where('campaign_id', $campaign->id)->whereDate('created_at', today())->sum('amount');
        $dailyBudgetReached = $campaign->daily_budget && $todaySpend >= (float) $campaign->daily_budget;
        if ($dailyBudgetReached) {
            $campaign->update(['status' => 'paused']);
            AdsModel::where('campaign_id', $campaign->id)->update(['is_active' => false]);
        }
    }

    private function verify(string $token): array
    {
        [$encoded, $signature] = array_pad(explode('.', $token, 2), 2, '');
        $expected = hash_hmac('sha256', $encoded, (string) config('app.key'));
        if ($signature === '' || ! hash_equals($expected, $signature)) {
            throw ValidationException::withMessages(['tracking_token' => ['Invalid ad tracking token.']]);
        }
        $padding = (4 - strlen($encoded) % 4) % 4;
        $decoded = base64_decode(strtr($encoded.str_repeat('=', $padding), '-_', '+/'), true);
        $payload = $decoded === false ? null : json_decode($decoded, true);
        if (! is_array($payload) || ($payload['expires_at'] ?? 0) < now()->timestamp) {
            throw ValidationException::withMessages(['tracking_token' => ['Expired ad tracking token.']]);
        }

        return $payload;
    }

    private function hashIdentity(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : hash_hmac('sha256', $value, (string) config('app.key'));
    }
}
