<?php

namespace App\Http\Controllers\Channel;

use App\Http\Controllers\Api\V4\ChannelCatalogController;

/** Safe discovery for released v3 clients, retaining their success envelope. */
class PublicChannelController extends ChannelCatalogController
{
    protected function success(mixed $data)
    {
        return response()->json(['status' => 'success', 'message' => 'OK', 'data' => $data]);
    }
}
