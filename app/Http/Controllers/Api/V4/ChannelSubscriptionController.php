<?php

namespace App\Http\Controllers\Api\V4;

use App\Http\Controllers\Channel\ChannelController;
use App\Http\Controllers\Controller;
use App\Models\Channel\Channel;
use App\Models\Channel\ChannelSubscriber;
use App\Support\Api\V4Response;
use Illuminate\Http\Request;

class ChannelSubscriptionController extends Controller
{
    public function __construct(private readonly ChannelController $channels) {}

    public function show(Request $request, string $channelId)
    {
        $channel = Channel::findOrFail($channelId);
        $membership = ChannelSubscriber::where('channel_id', $channelId)
            ->where('user_id', $this->userId($request))->first();

        return V4Response::success([
            'channel_id' => $channel->id,
            'membership' => $membership,
            'has_access' => $channel->status === 'active'
                && $membership !== null
                && $membership->status === 'active'
                && $membership->subscribed_at !== null
                && $membership->subscribed_at->lte(now())
                && $membership->expires_at !== null
                && $membership->expires_at->gt(now()),
        ]);
    }

    public function store(Request $request, string $channelId)
    {
        $request->merge(['user_id' => $this->userId($request)]);

        return $this->channels->subscribe($request, $channelId);
    }

    public function destroy(Request $request, string $channelId)
    {
        $membership = ChannelSubscriber::where('channel_id', $channelId)
            ->where('user_id', $this->userId($request))->firstOrFail();

        // Preserve existing immediate-cancellation semantics. Historical
        // cancelled rows must never become entitled again. Period-end renewal
        // cancellation needs its own state when verified billing is introduced.
        ChannelSubscriber::whereKey($membership->id)->where('status', 'active')
            ->update(['status' => 'cancelled']);

        return V4Response::success($membership->fresh(), 'Channel membership cancelled.');
    }

    private function userId(Request $request): string
    {
        return (string) $request->input('auth_user_id');
    }
}
