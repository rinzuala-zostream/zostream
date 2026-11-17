<?php

namespace App\Http\Controllers\Api\V4;

use App\Http\Controllers\Controller;
use App\Models\Channel\Channel;
use App\Support\Api\V4Response;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CreatorChannelController extends Controller
{
    public function index(Request $request)
    {
        return V4Response::success(Channel::where('user_id', $request->input('auth_user_id'))
            ->where('status', '!=', 'deleted')->latest('id')->paginate($this->perPage($request)));
    }

    public function show(Request $request, string $channelId)
    {
        $channel = $this->owned($request, $channelId);
        $contentCounts = $channel->contents()->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');

        return V4Response::success([
            'channel' => $channel,
            'summary' => [
                'contents' => (int) $contentCounts->sum(),
                'published' => (int) ($contentCounts['published'] ?? 0),
                'drafts' => (int) ($contentCounts['draft'] ?? 0),
                'private' => (int) ($contentCounts['private'] ?? 0),
                'active_members' => $channel->subscribers()->where('status', 'active')
                    ->where('subscribed_at', '<=', now())->where('expires_at', '>', now())->count(),
            ],
        ]);
    }

    public function update(Request $request, string $channelId)
    {
        return DB::transaction(function () use ($request, $channelId) {
            $channel = $this->owned($request, $channelId, true);
            $this->writable($channel);
            $request->validate([
                'name' => ['sometimes', 'required', 'string', 'max:255'],
                'slug' => ['sometimes', 'required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('channels', 'slug')->ignore($channel->id)],
                'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
                'logo' => ['sometimes', 'nullable', 'url:http,https', 'max:500'],
                'banner' => ['sometimes', 'nullable', 'url:http,https', 'max:500'],
                'user_id' => ['prohibited'], 'owner_user_id' => ['prohibited'],
                'status' => ['prohibited'], 'is_verified' => ['prohibited'],
            ]);
            $channel->update($request->only(['name', 'slug', 'description', 'logo', 'banner']));

            return V4Response::success($channel->fresh(), 'Channel profile saved.');
        });
    }

    public function plans(Request $request, string $channelId)
    {
        return V4Response::success($this->owned($request, $channelId)->plans()
            ->latest('id')->paginate($this->perPage($request)));
    }

    public function contents(Request $request, string $channelId)
    {
        $channel = $this->owned($request, $channelId);
        $data = $request->validate(['status' => ['sometimes', 'in:draft,published,private']]);

        return V4Response::success($channel->contents()
            ->when(isset($data['status']), fn ($query) => $query->where('status', $data['status']))
            ->latest('id')->paginate($this->perPage($request)));
    }

    public function storeContent(Request $request, string $channelId)
    {
        return DB::transaction(function () use ($request, $channelId) {
            $channel = $this->owned($request, $channelId, true);
            $this->writable($channel);
            $request->validate($this->contentRules());
            $data = $request->only(['title', 'description', 'thumbnail']);
            $content = $channel->contents()->create(array_merge($data, [
                'content_type' => 'video', 'access_type' => 'subscriber_only', 'status' => 'draft',
            ]));

            return V4Response::success($content, 'Video draft created.', status: 201);
        });
    }

    public function updateContent(Request $request, string $channelId, string $contentId)
    {
        return DB::transaction(function () use ($request, $channelId, $contentId) {
            $channel = $this->owned($request, $channelId, true);
            $this->writable($channel);
            $content = $channel->contents()->lockForUpdate()->findOrFail($contentId);
            $request->validate($this->contentRules(true));
            $content->fill($request->only(['title', 'description', 'thumbnail']));
            if ($content->isDirty() && $content->status === 'published') {
                $content->status = 'draft';
            }
            $content->save();

            return V4Response::success($content->fresh(), 'Video metadata saved. Publication requires staff review.');
        });
    }

    public function withdrawContent(Request $request, string $channelId, string $contentId)
    {
        return DB::transaction(function () use ($request, $channelId, $contentId) {
            $channel = $this->owned($request, $channelId, true);
            $this->writable($channel);
            $content = $channel->contents()->lockForUpdate()->findOrFail($contentId);
            $content->update(['status' => 'private']);

            return V4Response::success($content->fresh(), 'Video withdrawn.');
        });
    }

    private function owned(Request $request, string $channelId, bool $lock = false): Channel
    {
        return Channel::where('user_id', $request->input('auth_user_id'))
            ->where('status', '!=', 'deleted')
            ->when($lock, fn ($query) => $query->lockForUpdate())->findOrFail($channelId);
    }

    private function writable(Channel $channel): void
    {
        if (! in_array($channel->status, ['active', 'pending'], true)) {
            throw new AuthorizationException('This channel is suspended. Contact Zo Stream support.');
        }
    }

    private function contentRules(bool $partial = false): array
    {
        return [
            'title' => [$partial ? 'sometimes' : 'required', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'thumbnail' => ['sometimes', 'nullable', 'url:http,https', 'max:500'],
            'channel_id' => ['prohibited'], 'user_id' => ['prohibited'],
            'status' => ['prohibited'], 'access_type' => ['prohibited'], 'content_type' => ['prohibited'],
            'duration' => ['prohibited'], 'release_date' => ['prohibited'],
            'url' => ['prohibited'], 'media' => ['prohibited'], 'dash_mpd_key' => ['prohibited'], 'source_object_key' => ['prohibited'],
        ];
    }

    private function perPage(Request $request): int
    {
        $values = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        return (int) ($values['per_page'] ?? 20);
    }
}
