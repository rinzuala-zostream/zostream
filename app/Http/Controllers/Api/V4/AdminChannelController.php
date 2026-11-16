<?php

namespace App\Http\Controllers\Api\V4;

use App\Http\Controllers\Channel\ChannelController;
use App\Support\Api\V4Response;

/** Reuse channel management behind the authenticated V4 administrator boundary. */
class AdminChannelController extends ChannelController
{
    protected function success($data = null, string $message = 'OK', int $code = 200)
    {
        return V4Response::success($data, $message, status: $code);
    }

    protected function notFound(string $message)
    {
        return V4Response::error('CHANNEL_RESOURCE_NOT_FOUND', $message, 404);
    }
}
