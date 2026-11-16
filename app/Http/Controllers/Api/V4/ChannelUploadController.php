<?php

namespace App\Http\Controllers\Api\V4;

use App\Http\Controllers\Controller;
use App\Models\Channel\Channel;
use App\Models\Channel\ChannelUpload;
use App\Support\Api\V4Response;
use App\Support\WebpImageUploader;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Source uploads are private review assets, never streaming media records. */
class ChannelUploadController extends Controller
{
    protected bool $administrator = false;

    private function channel(Request $request, string $id, bool $write = false): Channel
    {
        $channel = Channel::where('status', '!=', 'deleted')
            ->when(! $this->administrator, fn ($query) => $query->where('user_id', $request->input('auth_user_id')))
            ->when($write, fn ($query) => $query->lockForUpdate())->findOrFail($id);
        if ($write && ! in_array($channel->status, ['pending', 'active'], true)) {
            throw new AuthorizationException('This channel is read-only.');
        }

        return $channel;
    }

    private function upload(Request $request, string $channelId, string $uploadId, bool $write = false): ChannelUpload
    {
        $this->channel($request, $channelId, $write);

        return ChannelUpload::where('channel_id', $channelId)
            ->when(! $this->administrator, fn ($query) => $query->where('user_id', $request->input('auth_user_id')))
            ->when($write, fn ($query) => $query->lockForUpdate())->findOrFail($uploadId);
    }

    private function editable(ChannelUpload $upload): void
    {
        if ($upload->status !== 'uploading' || $upload->expires_at->isPast()) {
            throw ValidationException::withMessages(['file' => 'This upload is closed or expired. Start a new upload.']);
        }
    }

    public function index(Request $request, string $channelId)
    {
        $this->channel($request, $channelId);
        $data = $request->validate(['content_id' => ['required', 'integer'], 'page' => ['sometimes', 'integer', 'min:1']]);

        return V4Response::success(ChannelUpload::where('channel_id', $channelId)->where('content_id', $data['content_id'])
            ->where('kind', 'source')->where('status', 'ready')->latest()->paginate(20));
    }

    public function start(Request $request, string $channelId)
    {
        return DB::transaction(function () use ($request, $channelId) {
            $channel = $this->channel($request, $channelId, true);
            $data = $request->validate([
                'filename' => ['required', 'string', 'max:200', 'regex:/^[^\\\\\/\x00-\x1f]+$/u'],
                'kind' => ['required', 'in:source,artwork'],
                'size' => ['required', 'integer', 'min:1'],
                'content_id' => ['nullable', 'integer'],
            ]);
            if (isset($data['content_id'])) {
                $channel->contents()->findOrFail($data['content_id']);
            }
            $extension = strtolower(pathinfo($data['filename'], PATHINFO_EXTENSION));
            $allowed = $data['kind'] === 'artwork' ? ['jpg', 'jpeg', 'png', 'webp'] : ($this->administrator ? ['mp4', 'mp3', 'm4a', 'wav', 'vtt', 'srt'] : ['mp4']);
            $max = config($data['kind'] === 'artwork' ? 'channel_uploads.max_artwork_bytes' : 'channel_uploads.max_source_bytes');
            if (! in_array($extension, $allowed, true) || $data['size'] > $max || ($data['kind'] === 'source' && ! isset($data['content_id']))) {
                throw ValidationException::withMessages(['file' => 'Choose a supported file within the upload limit. Save a video draft before uploading its source.']);
            }
            $pending = ChannelUpload::where('channel_id', $channelId)->where('status', 'uploading')->where('expires_at', '>', now())->count();
            $used = ChannelUpload::where('channel_id', $channelId)->where('kind', 'source')
                ->where(fn ($query) => $query->where('status', 'ready')->orWhere(fn ($query) => $query->where('status', 'uploading')->where('expires_at', '>', now())))->sum('size');
            if ($pending >= config('channel_uploads.max_pending_per_channel') || ($data['kind'] === 'source' && $used + $data['size'] > config('channel_uploads.max_source_bytes_per_channel'))) {
                throw ValidationException::withMessages(['file' => 'Channel upload capacity reached. Finish or cancel pending uploads, or contact Zo Stream support.']);
            }
            $upload = ChannelUpload::create(array_merge($data, [
                'id' => (string) Str::uuid(), 'channel_id' => $channel->id, 'user_id' => $request->input('auth_user_id'),
                'chunk_size' => config('channel_uploads.chunk_bytes'), 'status' => 'uploading',
                'expires_at' => now()->addHours(config('channel_uploads.session_hours')),
            ]));

            return V4Response::success($upload, 'Upload started.', status: 201);
        });
    }

    public function show(Request $request, string $channelId, string $uploadId)
    {
        return V4Response::success($this->upload($request, $channelId, $uploadId));
    }

    public function chunk(Request $request, string $channelId, string $uploadId, string $part)
    {
        return DB::transaction(function () use ($request, $channelId, $uploadId, $part) {
            $upload = $this->upload($request, $channelId, $uploadId, true);
            $this->editable($upload);
            $index = filter_var($part, FILTER_VALIDATE_INT);
            $count = (int) ceil($upload->size / $upload->chunk_size);
            if ($index === false || $index < 0 || $index >= $count) {
                throw ValidationException::withMessages(['file' => 'Invalid upload chunk.']);
            }
            $request->validate(['file' => ['required', 'file', 'max:'.ceil($upload->chunk_size / 1024)]]);
            $file = $request->file('file');
            $expected = min($upload->chunk_size, $upload->size - $index * $upload->chunk_size);
            if ($file->getSize() !== $expected) {
                throw ValidationException::withMessages(['file' => 'The upload chunk is incomplete. Retry this file.']);
            }
            Storage::disk('channel_uploads')->putFileAs($upload->directory().'/parts', $file, (string) $index);

            return V4Response::success(['part' => $index]);
        });
    }

    public function complete(Request $request, string $channelId, string $uploadId)
    {
        return DB::transaction(function () use ($request, $channelId, $uploadId) {
            $upload = $this->upload($request, $channelId, $uploadId, true);
            if ($upload->status === 'ready') {
                return V4Response::success($upload);
            }
            $this->editable($upload);
            $disk = Storage::disk('channel_uploads');
            $count = (int) ceil($upload->size / $upload->chunk_size);
            for ($i = 0; $i < $count; $i++) {
                if (! $disk->exists($upload->directory().'/parts/'.$i)) {
                    throw ValidationException::withMessages(['file' => 'Some chunks are missing. Retry this upload.']);
                }
            }
            $path = $upload->directory().'/source';
            $output = fopen($disk->path($path), 'wb');
            if ($output === false) {
                throw new \RuntimeException('Unable to assemble channel upload.');
            }
            try {
                for ($i = 0; $i < $count; $i++) {
                    $input = $disk->readStream($upload->directory().'/parts/'.$i);
                    if ($input === false) {
                        throw new \RuntimeException('Unable to read channel chunk.');
                    }
                    try {
                        $expected = min($upload->chunk_size, $upload->size - $i * $upload->chunk_size);
                        if (stream_copy_to_stream($input, $output) !== $expected) {
                            throw new \RuntimeException('Unable to assemble a complete channel upload.');
                        }
                    } finally {
                        fclose($input);
                    }
                }
            } finally {
                fclose($output);
            }
            $file = new UploadedFile($disk->path($path), $upload->filename, null, null, true);
            $rules = ['image', 'mimes:jpg,jpeg,png,webp', 'max:10240', 'dimensions:max_width=4096,max_height=4096'];
            // Extension and real MIME must agree; e.g. text renamed to .mp4 is not video.
            $extension = strtolower(pathinfo($upload->filename, PATHINFO_EXTENSION));
            if ($upload->kind === 'source') {
                $mimeByExtension = ['mp4' => ['video/mp4'], 'mp3' => ['audio/mpeg'], 'm4a' => ['audio/mp4', 'audio/x-m4a'], 'wav' => ['audio/wav', 'audio/x-wav'], 'vtt' => ['text/plain', 'text/vtt'], 'srt' => ['text/plain', 'application/x-subrip']];
                $rules = ['mimetypes:'.implode(',', $mimeByExtension[$extension] ?? [])];
            }
            Validator::make(['file' => $file], ['file' => array_merge(['required', 'file'], $rules)])->validate();
            if ($file->getSize() !== $upload->size) {
                throw ValidationException::withMessages(['file' => 'The assembled file size did not match.']);
            }
            $url = null;
            if ($upload->kind === 'artwork') {
                $urlRoot = config('filesystems.disks.r2.url');
                if (! is_string($urlRoot) || ! filter_var($urlRoot, FILTER_VALIDATE_URL) || parse_url($urlRoot, PHP_URL_SCHEME) !== 'https') {
                    throw ValidationException::withMessages(['file' => 'Artwork storage is not configured. Contact Zo Stream support.']);
                }
                $key = 'channels/'.$channelId.'/artwork/'.$upload->id.'.webp';
                $image = app(WebpImageUploader::class)->webpContents($file);
                if (! Storage::disk('r2')->put($key, $image, ['visibility' => 'public', 'ContentType' => 'image/webp'])) {
                    throw new \RuntimeException('Unable to store channel artwork.');
                }
                $url = rtrim($urlRoot, '/').'/'.$key;
            } else {
                // Lock the target as well; source replacement always requires staff review.
                $channel = $this->channel($request, $channelId);
                $channel->contents()->whereKey($upload->content_id)->lockForUpdate()->firstOrFail()->update(['status' => 'draft']);
            }
            $upload->update(['status' => 'ready', 'mime' => $upload->kind === 'artwork' ? 'image/webp' : $file->getMimeType(), 'url' => $url]);
            $disk->deleteDirectory($upload->directory().'/parts');
            if ($upload->kind === 'artwork') {
                $disk->delete($path);
            }

            return V4Response::success($upload->fresh(), 'File uploaded.');
        });
    }

    public function cancel(Request $request, string $channelId, string $uploadId)
    {
        return DB::transaction(function () use ($request, $channelId, $uploadId) {
            $upload = $this->upload($request, $channelId, $uploadId, true);
            if ($upload->status !== 'ready') {
                $upload->update(['status' => 'cancelled']);
                Storage::disk('channel_uploads')->deleteDirectory($upload->directory());
            }

            return V4Response::success(null, 'Upload closed.');
        });
    }
}
