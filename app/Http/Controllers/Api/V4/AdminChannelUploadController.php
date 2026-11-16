<?php

namespace App\Http\Controllers\Api\V4;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AdminChannelUploadController extends ChannelUploadController
{
    protected bool $administrator = true;

    public function download(Request $request, string $channelId, string $uploadId)
    {
        // Administrator route middleware authenticates every download.
        $upload = \App\Models\Channel\ChannelUpload::where('channel_id', $channelId)
            ->where('kind', 'source')->where('status', 'ready')->findOrFail($uploadId);
        $name = Str::slug(pathinfo($upload->filename, PATHINFO_FILENAME)) ?: 'source';
        $extension = strtolower(pathinfo($upload->filename, PATHINFO_EXTENSION));

        \Illuminate\Support\Facades\Log::info('Channel source download authorized', [
            'actor_id' => $request->input('auth_user_id'), 'channel_id' => $upload->channel_id, 'upload_id' => $upload->id,
        ]);

        return Storage::disk('channel_uploads')->download($upload->directory().'/source', $name.'.'.$extension, [
            'Content-Type' => 'application/octet-stream', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
