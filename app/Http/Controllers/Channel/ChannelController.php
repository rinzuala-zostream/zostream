<?php

namespace App\Http\Controllers\Channel;

use App\Http\Controllers\Controller;
use App\Models\Channel\Channel;
use App\Models\Channel\ChannelContent;
use App\Models\Channel\ChannelContentMedia;
use App\Models\Channel\ChannelContentPpv;
use App\Models\Channel\ChannelContentRental;
use App\Models\Channel\ChannelContentRentalHistory;
use App\Models\Channel\ChannelSubscriber;
use App\Models\Channel\ChannelSubscriptionHistory;
use App\Models\Channel\ChannelSubscriptionPlan;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ChannelController extends Controller
{
    public function index(Request $request)
    {
        try {
            $query = Channel::query()->withCount(['plans', 'subscribers', 'contents'])->latest();

            if ($request->filled('user_id')) {
                $query->where('user_id', $request->get('user_id'));
            }

            if ($request->filled('status')) {
                $query->where('status', $request->get('status'));
            }

            if ($request->filled('search')) {
                $search = trim((string) $request->get('search'));
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('slug', 'like', "%{$search}%");
                });
            }

            return $this->success($query->paginate($this->perPage($request)));
        } catch (Exception $e) {
            return $this->fail('Failed to fetch channels', $e);
        }
    }

    public function store(Request $request)
    {
        try {
            $validated = $request->validate($this->channelRules());
            $validated['slug'] = $this->uniqueSlug($validated['slug'] ?? $validated['name']);
            $validated['is_verified'] = $validated['is_verified'] ?? false;
            $validated['status'] = $validated['status'] ?? 'pending';

            $channel = Channel::create($validated);

            return $this->success($channel, 'Channel created successfully', 201);
        } catch (Exception $e) {
            return $this->fail('Failed to create channel', $e);
        }
    }

    public function show($channelId)
    {
        $channel = Channel::with(['plans', 'contents.media', 'contents.ppv'])->find($channelId);

        if (!$channel) {
            return $this->notFound('Channel not found');
        }

        return $this->success($channel);
    }

    public function update(Request $request, $channelId)
    {
        try {
            $channel = Channel::find($channelId);

            if (!$channel) {
                return $this->notFound('Channel not found');
            }

            $validated = $request->validate($this->channelRules($channel->id));

            if (isset($validated['slug'])) {
                $validated['slug'] = $this->uniqueSlug($validated['slug'], $channel->id);
            }

            $channel->update($validated);

            return $this->success($channel->fresh(), 'Channel updated successfully');
        } catch (Exception $e) {
            return $this->fail('Failed to update channel', $e);
        }
    }

    public function destroy($channelId)
    {
        $channel = Channel::find($channelId);

        if (!$channel) {
            return $this->notFound('Channel not found');
        }

        $channel->update(['status' => 'deleted']);

        return $this->success($channel->fresh(), 'Channel deleted successfully');
    }

    public function plans(Request $request, $channelId)
    {
        $channel = Channel::find($channelId);

        if (!$channel) {
            return $this->notFound('Channel not found');
        }

        $query = $channel->plans()->latest();

        if ($request->filled('is_active')) {
            $query->where('is_active', filter_var($request->get('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        return $this->success($query->paginate($this->perPage($request)));
    }

    public function storePlan(Request $request, $channelId)
    {
        try {
            $channel = Channel::find($channelId);

            if (!$channel) {
                return $this->notFound('Channel not found');
            }

            $validated = $request->validate($this->planRules());
            $validated['channel_id'] = $channel->id;
            $validated['final_price'] = $this->finalPrice($validated);
            $validated['is_active'] = $validated['is_active'] ?? true;

            $plan = ChannelSubscriptionPlan::create($validated);

            return $this->success($plan, 'Plan created successfully', 201);
        } catch (Exception $e) {
            return $this->fail('Failed to create plan', $e);
        }
    }

    public function showPlan($planId)
    {
        $plan = ChannelSubscriptionPlan::with('channel')->find($planId);

        if (!$plan) {
            return $this->notFound('Plan not found');
        }

        return $this->success($plan);
    }

    public function updatePlan(Request $request, $planId)
    {
        try {
            $plan = ChannelSubscriptionPlan::find($planId);

            if (!$plan) {
                return $this->notFound('Plan not found');
            }

            $validated = $request->validate($this->planRules(true));
            $data = array_merge($plan->only(['price', 'discount_percent']), $validated);

            if (isset($validated['price']) || isset($validated['discount_percent'])) {
                $validated['final_price'] = $this->finalPrice($data);
            }

            $plan->update($validated);

            return $this->success($plan->fresh(), 'Plan updated successfully');
        } catch (Exception $e) {
            return $this->fail('Failed to update plan', $e);
        }
    }

    public function destroyPlan($planId)
    {
        $plan = ChannelSubscriptionPlan::find($planId);

        if (!$plan) {
            return $this->notFound('Plan not found');
        }

        $plan->update(['is_active' => false]);

        return $this->success($plan->fresh(), 'Plan deactivated successfully');
    }

    public function subscribers(Request $request, $channelId)
    {
        $channel = Channel::find($channelId);

        if (!$channel) {
            return $this->notFound('Channel not found');
        }

        $query = $channel->subscribers()->with(['plan', 'user'])->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->get('status'));
        }

        return $this->success($query->paginate($this->perPage($request)));
    }

    public function subscribe(Request $request, $channelId)
    {
        try {
            $channel = Channel::find($channelId);

            if (!$channel) {
                return $this->notFound('Channel not found');
            }

            $validated = $request->validate([
                'user_id' => ['required', 'string', 'exists:user,uid'],
                'plan_id' => ['required', 'integer', 'exists:channel_subscription_plans,id'],
                'transaction_id' => ['nullable', 'string', 'max:255'],
                'payment_method' => ['nullable', 'string', 'max:50'],
                'status' => ['nullable', Rule::in(['pending', 'success', 'failed', 'refunded'])],
            ]);

            $plan = ChannelSubscriptionPlan::where('channel_id', $channel->id)
                ->where('is_active', true)
                ->find($validated['plan_id']);

            if (!$plan) {
                return $this->notFound('Active plan not found for this channel');
            }

            $subscriber = DB::transaction(function () use ($validated, $channel, $plan) {
                $start = now();
                $end = $start->copy()->addDays($plan->duration_days);
                $historyStatus = $validated['status'] ?? 'success';

                ChannelSubscriptionHistory::create([
                    'channel_id' => $channel->id,
                    'user_id' => $validated['user_id'],
                    'plan_id' => $plan->id,
                    'amount' => $plan->final_price ?? $plan->price,
                    'transaction_id' => $validated['transaction_id'] ?? null,
                    'payment_method' => $validated['payment_method'] ?? null,
                    'start_date' => $start,
                    'end_date' => $end,
                    'status' => $historyStatus,
                    'created_at' => now(),
                ]);

                if ($historyStatus !== 'success') {
                    return null;
                }

                return ChannelSubscriber::updateOrCreate(
                    ['channel_id' => $channel->id, 'user_id' => $validated['user_id']],
                    [
                        'plan_id' => $plan->id,
                        'subscribed_at' => $start,
                        'expires_at' => $end,
                        'status' => 'active',
                    ]
                );
            });

            return $this->success(
                $subscriber?->load('plan') ?? null,
                $subscriber ? 'Subscription activated successfully' : 'Subscription payment history recorded',
                201
            );
        } catch (Exception $e) {
            return $this->fail('Failed to subscribe', $e);
        }
    }

    public function cancelSubscription($channelId, $userId)
    {
        $subscriber = ChannelSubscriber::where('channel_id', $channelId)->where('user_id', $userId)->first();

        if (!$subscriber) {
            return $this->notFound('Subscription not found');
        }

        $subscriber->update(['status' => 'cancelled']);

        return $this->success($subscriber->fresh(), 'Subscription cancelled successfully');
    }

    public function subscriptionHistory(Request $request, $channelId)
    {
        $query = ChannelSubscriptionHistory::with(['plan', 'user'])
            ->where('channel_id', $channelId)
            ->latest('created_at');

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->get('user_id'));
        }

        return $this->success($query->paginate($this->perPage($request)));
    }

    public function contents(Request $request, $channelId)
    {
        $channel = Channel::find($channelId);

        if (!$channel) {
            return $this->notFound('Channel not found');
        }

        $query = $channel->contents()->with(['media', 'ppv'])->latest();

        foreach (['content_type', 'access_type', 'status'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->get($filter));
            }
        }

        return $this->success($query->paginate($this->perPage($request)));
    }

    public function storeContent(Request $request, $channelId)
    {
        try {
            $channel = Channel::find($channelId);

            if (!$channel) {
                return $this->notFound('Channel not found');
            }

            $validated = $request->validate($this->contentRules());
            $validated['channel_id'] = $channel->id;

            $content = ChannelContent::create($validated);

            return $this->success($content, 'Content created successfully', 201);
        } catch (Exception $e) {
            return $this->fail('Failed to create content', $e);
        }
    }

    public function showContent($contentId)
    {
        $content = ChannelContent::with(['channel', 'media', 'ppv'])->find($contentId);

        if (!$content) {
            return $this->notFound('Content not found');
        }

        return $this->success($content);
    }

    public function updateContent(Request $request, $contentId)
    {
        try {
            $content = ChannelContent::find($contentId);

            if (!$content) {
                return $this->notFound('Content not found');
            }

            $validated = $request->validate($this->contentRules(true));
            $content->update($validated);

            return $this->success($content->fresh(['media', 'ppv']), 'Content updated successfully');
        } catch (Exception $e) {
            return $this->fail('Failed to update content', $e);
        }
    }

    public function destroyContent($contentId)
    {
        $content = ChannelContent::find($contentId);

        if (!$content) {
            return $this->notFound('Content not found');
        }

        $content->delete();

        return $this->success(null, 'Content deleted successfully');
    }

    public function storeMedia(Request $request, $contentId)
    {
        try {
            if (!ChannelContent::whereKey($contentId)->exists()) {
                return $this->notFound('Content not found');
            }

            $validated = $request->validate($this->mediaRules());
            $validated['content_id'] = $contentId;
            $validated['created_at'] = now();

            $media = ChannelContentMedia::create($validated);

            return $this->success($media, 'Media created successfully', 201);
        } catch (Exception $e) {
            return $this->fail('Failed to create media', $e);
        }
    }

    public function updateMedia(Request $request, $mediaId)
    {
        try {
            $media = ChannelContentMedia::find($mediaId);

            if (!$media) {
                return $this->notFound('Media not found');
            }

            $validated = $request->validate($this->mediaRules(true));
            $media->update($validated);

            return $this->success($media->fresh(), 'Media updated successfully');
        } catch (Exception $e) {
            return $this->fail('Failed to update media', $e);
        }
    }

    public function destroyMedia($mediaId)
    {
        $media = ChannelContentMedia::find($mediaId);

        if (!$media) {
            return $this->notFound('Media not found');
        }

        $media->delete();

        return $this->success(null, 'Media deleted successfully');
    }

    public function upsertPpv(Request $request, $contentId)
    {
        try {
            if (!ChannelContent::whereKey($contentId)->exists()) {
                return $this->notFound('Content not found');
            }

            $validated = $request->validate([
                'price' => ['required', 'numeric', 'min:0'],
                'rental_days' => ['nullable', 'integer', 'min:1'],
                'is_active' => ['nullable', 'boolean'],
            ]);

            $ppv = ChannelContentPpv::updateOrCreate(
                ['content_id' => $contentId],
                [
                    'price' => $validated['price'],
                    'rental_days' => $validated['rental_days'] ?? 7,
                    'is_active' => $validated['is_active'] ?? true,
                ]
            );

            return $this->success($ppv, 'PPV settings saved successfully');
        } catch (Exception $e) {
            return $this->fail('Failed to save PPV settings', $e);
        }
    }

    public function destroyPpv($contentId)
    {
        $ppv = ChannelContentPpv::where('content_id', $contentId)->first();

        if (!$ppv) {
            return $this->notFound('PPV settings not found');
        }

        $ppv->delete();

        return $this->success(null, 'PPV settings deleted successfully');
    }

    public function rentContent(Request $request, $contentId)
    {
        try {
            $content = ChannelContent::with('ppv')->find($contentId);

            if (!$content) {
                return $this->notFound('Content not found');
            }

            if (!$content->ppv || !$content->ppv->is_active) {
                return response()->json(['status' => 'error', 'message' => 'PPV is not active for this content'], 422);
            }

            $validated = $request->validate([
                'user_id' => ['required', 'string', 'exists:user,uid'],
                'transaction_id' => ['nullable', 'string', 'max:255'],
                'payment_method' => ['nullable', 'string', 'max:50'],
                'status' => ['nullable', Rule::in(['pending', 'success', 'failed', 'refunded'])],
            ]);

            $rental = DB::transaction(function () use ($validated, $content) {
                $start = now();
                $end = $start->copy()->addDays($content->ppv->rental_days);
                $historyStatus = $validated['status'] ?? 'success';

                ChannelContentRentalHistory::create([
                    'user_id' => $validated['user_id'],
                    'content_id' => $content->id,
                    'amount' => $content->ppv->price,
                    'transaction_id' => $validated['transaction_id'] ?? null,
                    'payment_method' => $validated['payment_method'] ?? null,
                    'rented_at' => $start,
                    'expires_at' => $end,
                    'status' => $historyStatus,
                    'created_at' => now(),
                ]);

                if ($historyStatus !== 'success') {
                    return null;
                }

                return ChannelContentRental::updateOrCreate(
                    ['user_id' => $validated['user_id'], 'content_id' => $content->id],
                    [
                        'rented_at' => $start,
                        'expires_at' => $end,
                        'status' => 'active',
                    ]
                );
            });

            return $this->success(
                $rental,
                $rental ? 'Content rental activated successfully' : 'Rental payment history recorded',
                201
            );
        } catch (Exception $e) {
            return $this->fail('Failed to rent content', $e);
        }
    }

    public function cancelRental($contentId, $userId)
    {
        $rental = ChannelContentRental::where('content_id', $contentId)->where('user_id', $userId)->first();

        if (!$rental) {
            return $this->notFound('Rental not found');
        }

        $rental->update(['status' => 'cancelled']);

        return $this->success($rental->fresh(), 'Rental cancelled successfully');
    }

    public function rentalHistory(Request $request, $contentId)
    {
        $query = ChannelContentRentalHistory::with('user')
            ->where('content_id', $contentId)
            ->latest('created_at');

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->get('user_id'));
        }

        return $this->success($query->paginate($this->perPage($request)));
    }

    private function channelRules(?int $ignoreId = null): array
    {
        $required = $ignoreId ? 'sometimes' : 'required';

        return [
            'user_id' => [$required, 'string', 'exists:user,uid'],
            'name' => [$required, 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', Rule::unique('channels', 'slug')->ignore($ignoreId)],
            'description' => ['nullable', 'string'],
            'logo' => ['nullable', 'string', 'max:500'],
            'banner' => ['nullable', 'string', 'max:500'],
            'is_verified' => ['nullable', 'boolean'],
            'status' => ['nullable', Rule::in(['pending', 'active', 'suspended', 'deleted'])],
        ];
    }

    private function planRules(bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'name' => ['nullable', 'string', 'max:100'],
            'duration_days' => [$required, 'integer', 'min:1'],
            'price' => [$required, 'numeric', 'min:0'],
            'discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'final_price' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    private function contentRules(bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'content_type' => [$required, Rule::in(['video', 'audio', 'live', 'podcast', 'article'])],
            'access_type' => ['nullable', Rule::in(['free', 'subscriber_only', 'ppv'])],
            'thumbnail' => ['nullable', 'string', 'max:500'],
            'duration' => ['nullable', 'integer', 'min:0'],
            'release_date' => ['nullable', 'date'],
            'status' => ['nullable', Rule::in(['draft', 'published', 'private'])],
        ];
    }

    private function mediaRules(bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'media_type' => ['nullable', Rule::in(['video', 'audio', 'subtitle', 'thumbnail'])],
            'quality' => ['nullable', 'string', 'max:50'],
            'language' => ['nullable', 'string', 'max:20'],
            'url' => [$required, 'string'],
            'file_size' => ['nullable', 'integer', 'min:0'],
        ];
    }

    private function uniqueSlug(string $value, ?int $ignoreId = null): string
    {
        $slug = Str::slug($value);
        $base = $slug !== '' ? $slug : Str::random(8);
        $counter = 1;

        while (Channel::where('slug', $slug)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists()) {
            $slug = "{$base}-{$counter}";
            $counter++;
        }

        return $slug;
    }

    private function finalPrice(array $data): float
    {
        if (isset($data['final_price'])) {
            return (float) $data['final_price'];
        }

        $price = (float) ($data['price'] ?? 0);
        $discount = (float) ($data['discount_percent'] ?? 0);

        return round($price - ($price * $discount / 100), 2);
    }

    private function perPage(Request $request): int
    {
        return min(max((int) $request->get('per_page', 15), 1), 100);
    }

    private function success($data = null, string $message = 'OK', int $code = 200)
    {
        return response()->json([
            'status' => 'success',
            'message' => $message,
            'data' => $data,
        ], $code);
    }

    private function notFound(string $message)
    {
        return response()->json([
            'status' => 'error',
            'message' => $message,
        ], 404);
    }

    private function fail(string $message, Exception $e)
    {
        if ($e instanceof ValidationException) {
            throw $e;
        }

        Log::error($message, ['error' => $e->getMessage()]);

        return response()->json([
            'status' => 'error',
            'message' => $message,
            'error' => config('app.debug') ? $e->getMessage() : null,
        ], 500);
    }
}
