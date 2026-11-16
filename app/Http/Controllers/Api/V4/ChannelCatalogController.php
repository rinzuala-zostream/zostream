<?php

namespace App\Http\Controllers\Api\V4;

use App\Http\Controllers\Controller;
use App\Models\Channel\Channel;
use App\Models\Channel\ChannelContent;
use App\Models\Channel\ChannelSubscriptionPlan;
use App\Support\Api\V4Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class ChannelCatalogController extends Controller
{
    private const CHANNEL_FIELDS = ['id', 'name', 'slug', 'description', 'logo', 'banner', 'is_verified', 'status', 'created_at', 'updated_at'];

    private const CONTENT_FIELDS = ['id', 'channel_id', 'title', 'description', 'content_type', 'access_type', 'thumbnail', 'duration', 'release_date', 'status', 'created_at', 'updated_at'];

    public function index(Request $request)
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:200'],
            'user_id' => ['sometimes', 'string', 'max:255'],
        ]);
        $query = Channel::query()->select(self::CHANNEL_FIELDS)->where('status', 'active');

        if (! empty($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }
        if (! empty($filters['search'])) {
            $query->where(function (Builder $query) use ($filters) {
                $query->where('name', 'like', '%'.$filters['search'].'%')
                    ->orWhere('slug', 'like', '%'.$filters['search'].'%');
            });
        }

        return $this->success($query->latest('id')->paginate($this->perPage($request)));
    }

    public function show(string $channelId)
    {
        $channel = Channel::query()->select(self::CHANNEL_FIELDS)
            ->where('status', 'active')->findOrFail($channelId);
        // Keep the detail preview bounded; the paginated endpoints expose the catalog.
        $channel->setRelation('plans', $channel->plans()->where('is_active', true)->latest('id')->limit(15)->get());
        $channel->setRelation('contents', $this->publishedContents($channelId)->latest('id')->limit(15)->get());

        return $this->success($channel);
    }

    public function plans(Request $request, string $channelId)
    {
        $channel = Channel::where('status', 'active')->findOrFail($channelId);

        return $this->success($channel->plans()->where('is_active', true)
            ->latest('id')->paginate($this->perPage($request)));
    }

    public function showPlan(string $planId)
    {
        return $this->success(ChannelSubscriptionPlan::where('is_active', true)
            ->whereHas('channel', fn (Builder $query) => $query->where('status', 'active'))
            ->findOrFail($planId));
    }

    public function contents(Request $request, string $channelId)
    {
        Channel::where('status', 'active')->findOrFail($channelId);
        $filters = $request->validate([
            'content_type' => ['sometimes', 'in:video,audio,live,podcast,article'],
            'access_type' => ['sometimes', 'in:free,subscriber_only,ppv'],
        ]);
        $query = $this->publishedContents($channelId);
        foreach ($filters as $field => $value) {
            $query->where($field, $value);
        }

        return $this->success($query->latest('id')->paginate($this->perPage($request)));
    }

    public function showContent(string $contentId)
    {
        return $this->success($this->publishedContents()->findOrFail($contentId));
    }

    private function publishedContents(?string $channelId = null): Builder
    {
        return ChannelContent::query()->select(self::CONTENT_FIELDS)
            ->where('status', 'published')
            ->whereHas('channel', fn (Builder $query) => $query->where('status', 'active'))
            ->where(fn (Builder $query) => $query->whereNull('release_date')->orWhere('release_date', '<=', now()))
            ->when($channelId !== null, fn (Builder $query) => $query->where('channel_id', $channelId));
    }

    private function perPage(Request $request): int
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        return (int) ($validated['per_page'] ?? 15);
    }

    protected function success(mixed $data)
    {
        return V4Response::success($data);
    }
}
