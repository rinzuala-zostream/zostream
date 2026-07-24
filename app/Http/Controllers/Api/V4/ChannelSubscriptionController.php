<?php

namespace App\Http\Controllers\Api\V4;

use App\Http\Controllers\Channel\ChannelController;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class ChannelSubscriptionController extends Controller
{
    public function __construct(private readonly ChannelController $channels) {}

    public function store(Request $request, string $channelId)
    {
        $request->merge(['user_id' => $this->userId($request)]);

        return $this->channels->subscribe($request, $channelId);
    }

    public function destroy(Request $request, string $channelId)
    {
        return $this->channels->cancelSubscription($channelId, $this->userId($request));
    }

    private function userId(Request $request): string
    {
        return (string) $request->input('auth_user_id');
    }
}
