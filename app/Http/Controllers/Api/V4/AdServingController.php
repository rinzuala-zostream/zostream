<?php

namespace App\Http\Controllers\Api\V4;

use App\Http\Controllers\Controller;
use App\Support\Api\V4Response;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class AdServingController extends Controller
{
    public function serve(Request $request)
    {
        $data = $request->validate([
            'placement' => ['required', 'string', 'max:60'],
            'platform' => ['nullable', 'string', 'max:30'],
        ]);

        if (! $this->servingSchemaIsReady()) {
            Log::warning('Ad serving skipped because the campaign schema is incomplete.', [
                'placement' => $data['placement'],
                'platform' => $data['platform'] ?? null,
            ]);

            return V4Response::success(null, 'No eligible ad is available.');
        }

        $now = now();
        $hasLegacyAdUrl = Schema::hasTable('ads')
            && Schema::hasColumn('ads', 'num')
            && Schema::hasColumn('ads', 'ads_url');

        try {
            $query = DB::table('ad_campaign_placements as assignment')
                ->join('ad_campaigns as campaign', 'campaign.id', '=', 'assignment.campaign_id')
                ->join('ad_creatives as creative', 'creative.id', '=', 'assignment.creative_id')
                ->join('ad_placement_slots as slot', 'slot.id', '=', 'assignment.placement_slot_id')
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
                ->inRandomOrder();

            if ($hasLegacyAdUrl) {
                $query->leftJoin('ads as live_ad', 'live_ad.num', '=', 'creative.existing_ad_num');
            }

            $creative = $query->select([
                'campaign.id as campaign_id', 'campaign.billing_model', 'creative.id as creative_id',
                'creative.name', 'creative.type', 'creative.media_url', 'creative.thumbnail_url',
                'creative.target_url', 'creative.duration_seconds', 'creative.skip_after_seconds',
                'creative.is_skippable', 'slot.id as placement_slot_id', 'slot.code as placement',
                $hasLegacyAdUrl ? 'live_ad.ads_url' : DB::raw('NULL as ads_url'),
            ])
                ->first();
        } catch (QueryException $exception) {
            Log::warning('Ad serving query failed; returning an empty slot.', [
                'placement' => $data['placement'],
                'platform' => $data['platform'] ?? null,
                'sql_state' => $exception->errorInfo[0] ?? null,
                'driver_code' => $exception->errorInfo[1] ?? null,
                'error' => $exception->getMessage(),
            ]);

            return V4Response::success(null, 'No eligible ad is available.');
        }

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

    private function servingSchemaIsReady(): bool
    {
        $requiredColumns = [
            'ad_campaign_placements' => ['campaign_id', 'creative_id', 'placement_slot_id', 'priority', 'is_active'],
            'ad_campaigns' => ['id', 'billing_model', 'status', 'start_at', 'end_at', 'target_quantity', 'consumed_quantity'],
            'ad_creatives' => ['id', 'name', 'type', 'media_url', 'thumbnail_url', 'target_url', 'duration_seconds', 'skip_after_seconds', 'is_skippable', 'existing_ad_num', 'is_active'],
            'ad_placement_slots' => ['id', 'code', 'platform', 'is_active'],
        ];

        foreach ($requiredColumns as $table => $columns) {
            if (! Schema::hasTable($table)) {
                return false;
            }

            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    return false;
                }
            }
        }

        return true;
    }
}
