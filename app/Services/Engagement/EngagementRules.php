<?php

namespace App\Services\Engagement;

use App\Models\New\Subscription;
use App\Support\MizoOnlyContent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class EngagementRules
{
    private array $subscriptions = [];

    public function candidate(object $user, CarbonImmutable $now): ?array
    {
        unset($this->subscriptions[$user->user_id]);
        if (! $user->enabled || ! $user->last_active_at || $user->unopened_count >= 5) {
            return null;
        }
        $cooldown = (int) config($user->unopened_count >= 2 ? 'engagement.ignored_cooldown_hours' : 'engagement.cooldown_hours');
        if ($user->last_sent_at && CarbonImmutable::parse($user->last_sent_at, 'UTC')->addHours($cooldown)->gt($now)) {
            return null;
        }
        $recent = DB::table('engagement_content')->where('user_id', $user->user_id)
            ->where('last_watch_at', '>=', $now->subDays((int) config('engagement.candidate_max_age_days')))
            ->orderByDesc('last_watch_at')->limit(30)->get();

        foreach ($recent as $content) {
            if (! $content->completed_at && $content->position_ms > 0 && $content->duration_ms > 0 && $content->position_ms / $content->duration_ms < .9) {
                $candidate = $this->make('continue_watching', $content->content_type, $content->content_id, $content->last_watch_at,
                    CarbonImmutable::parse($content->last_watch_at, 'UTC')->addHours((int) config('engagement.continue_after_hours')));
                if ($this->usable($user, $candidate, $now)) {
                    return $candidate;
                }
            }
        }
        $wishlist = DB::table('wist_list')->where('uid', $user->user_id)
            ->where('created_at', '<=', $now->subDays((int) config('engagement.watchlist_after_days'))->setTimezone(config('app.timezone'))->toDateTimeString())
            ->orderByDesc('created_at')->limit(20)->get();
        foreach ($wishlist as $item) {
            if (! $this->watched($user->user_id, 'movie', (string) $item->movie_id)) {
                $added = CarbonImmutable::parse($item->created_at, config('app.timezone'))->utc();
                $candidate = $this->make('watchlist', 'movie', (string) $item->movie_id, $added->toDateTimeString(),
                    $added->addDays((int) config('engagement.watchlist_after_days')), (string) $item->id);
                if ($this->usable($user, $candidate, $now)) {
                    return $candidate;
                }
            }
        }
        $subscription = $this->subscription($user->user_id);
        if ($subscription && CarbonImmutable::parse($subscription->start_at)->addDays((int) config('engagement.subscriber_after_days'))->lte($now)
            && (! $user->last_watch_at || CarbonImmutable::parse($user->last_watch_at, 'UTC')->lt(CarbonImmutable::parse($subscription->start_at)))) {
            if ($movie = $this->recommend($user)) {
                $candidate = $this->make('unused_subscription', 'movie', (string) $movie->id, CarbonImmutable::instance($subscription->start_at)->utc()->toDateTimeString(),
                    CarbonImmutable::parse($subscription->start_at)->addDays((int) config('engagement.subscriber_after_days')), (string) $subscription->id);
                if ($this->usable($user, $candidate, $now)) {
                    return $candidate;
                }
            }
        }
        $active = CarbonImmutable::parse($user->last_active_at, 'UTC');
        // Choose the most relevant current milestone; do not replay missed milestones.
        foreach (array_reverse(config('engagement.comeback_days')) as $days) {
            if ($user->has_app_activity && $active->addDays($days)->lte($now)) {
                if ($movie = $this->recommend($user)) {
                    $candidate = $this->make('comeback', 'movie', (string) $movie->id, $user->last_active_at, $active->addDays($days), (string) $days);
                    if ($this->usable($user, $candidate, $now)) {
                        return $candidate;
                    }
                }
                break;
            }
        }
        if ($user->interest_at && CarbonImmutable::parse($user->interest_at, 'UTC')->gte($now->subDays(7))
            && (! $user->last_watch_at || CarbonImmutable::parse($user->last_watch_at, 'UTC')->lt(CarbonImmutable::parse($user->interest_at, 'UTC')))
            && ! $this->watched($user->user_id, 'movie', (string) $user->interest_content_id)
            && ($movie = $this->recommend($user, (string) $user->interest_content_id))) {
            $candidate = $this->make('search_interest', 'movie', (string) $movie->id, $user->interest_at,
                CarbonImmutable::parse($user->interest_at, 'UTC')->addHours((int) config('engagement.search_after_hours')), (string) $user->interest_content_id);
            if ($this->usable($user, $candidate, $now)) {
                return $candidate;
            }
        }
        $finished = $recent->first(fn ($item) => $item->completed_at !== null);
        if ($finished && $finished->content_type === 'movie'
            && (! $user->last_watch_at || CarbonImmutable::parse($finished->completed_at, 'UTC')->gte(CarbonImmutable::parse($user->last_watch_at, 'UTC')))
            && ($movie = $this->recommend($user, $finished->content_id))) {
            $candidate = $this->make('post_watch', 'movie', (string) $movie->id, $finished->completed_at,
                CarbonImmutable::parse($finished->completed_at, 'UTC')->addHours((int) config('engagement.post_watch_after_hours')), $finished->content_id);
            if ($this->usable($user, $candidate, $now)) {
                return $candidate;
            }
        }

        return null;
    }

    public function dedupe(string $userId, array $candidate): string
    {
        // A recommendation changing must not send the same journey step twice.
        return hash('sha256', json_encode([$userId, $candidate['rule'], $candidate['source_key'], $candidate['source_at']], JSON_THROW_ON_ERROR));
    }

    public function content(object $user, string $type, string $id): ?array
    {
        $episode = null;
        if ($type === 'episode') {
            $episode = DB::table('episodes')->join('seasons', 'seasons.id', '=', 'episodes.season_id')
                ->where('episodes.id', $id)->where('episodes.status', 'Published')->where('episodes.is_active', 1)
                ->where('episodes.isPayPerView', 0)->where('seasons.status', 'Published')->where('seasons.isPayPerView', 0)
                ->select('episodes.id', 'episodes.title', 'episodes.isPremium', 'seasons.movie_id')->first();
            if (! $episode || ($episode->isPremium && ! $this->subscription($user->user_id))) {
                return null;
            }
        }
        $query = $this->catalogue($user);
        $movie = $episode ? $query->where('num', $episode->movie_id)->first() : $query->where('id', $id)->first();
        if (! $movie) {
            return null;
        }

        return ['movie_id' => (string) $movie->id, 'title' => (string) ($episode?->title ?? $movie->title),
            'image' => (string) ($movie->poster ?? ''), 'episode_id' => $episode ? (string) $episode->id : null];
    }

    private function catalogue(object $user)
    {
        return DB::table('movie')->where('status', 'Published')->where('isEnable', 1)
            ->where('isAgeRestricted', 0)->where('isPayPerView', 0)
            ->when($user->content_mode !== 'adult', fn ($query) => $query->where('isChildMode', 1))
            ->when(MizoOnlyContent::appliesTo($user->user_id), fn ($query) => $query->where('isMizo', 1))
            ->when(! $this->subscription($user->user_id), fn ($query) => $query->where('isPremium', 0));
    }

    private function subscription(string $userId): ?object
    {
        if (! array_key_exists($userId, $this->subscriptions)) {
            $this->subscriptions[$userId] = Subscription::query()->currentlyActive()->where('user_id', $userId)->where('start_at', '<=', now())->orderByDesc('end_at')->first();
        }

        return $this->subscriptions[$userId];
    }

    private function recommend(object $user, ?string $seed = null): ?object
    {
        // Cache a bounded shared catalogue pool. No Python process or full-history scan.
        $genre = $seed ? (string) DB::table('movie')->where('id', $seed)->value('genre') : '';
        $genre = trim(explode(',', $genre)[0]);
        $premium = (bool) $this->subscription($user->user_id);
        $key = hash('sha256', json_encode([$user->content_mode, MizoOnlyContent::appliesTo($user->user_id), $premium, $genre]));
        $pool = Cache::remember('engagement:catalogue:'.$key, (int) config('engagement.recommendation_cache_seconds'), function () use ($user, $genre) {
            $query = $this->catalogue($user);
            if ($genre !== '') {
                $query->where('genre', 'like', '%'.addcslashes($genre, '%_\\').'%');
            }

            return $query->orderByDesc('views')->limit(50)->get(['id']);
        });
        $ids = $pool->pluck('id')->map(fn ($id) => (string) $id)->all();
        $seen = DB::table('engagement_content')->where('user_id', $user->user_id)->where('content_type', 'movie')->whereIn('content_id', $ids)->pluck('content_id')->all();
        // Legacy positions also suppress titles watched before engagement tracking was enabled.
        $legacy = DB::table('watch_position')->where('user_id', $user->user_id)->whereIn('movie_id', $ids)
            ->where(fn ($query) => $query->whereNull('movie_type')->orWhere('movie_type', '!=', 'episode'))
            ->where('position', '>', 0)->pluck('movie_id')->all();
        $sent = DB::table('engagement_deliveries')->where('user_id', $user->user_id)->where('content_type', 'movie')->where('status', 'sent')
            ->where('sent_at', '>=', now('UTC')->subDays(30))->whereIn('content_id', $ids)->pluck('content_id')->all();
        $excluded = array_merge($seen, $legacy, $sent, [$seed]);

        return $pool->first(fn ($movie) => ! in_array((string) $movie->id, $excluded, true));
    }

    private function watched(string $userId, string $type, string $id): bool
    {
        if (DB::table('engagement_content')->where('user_id', $userId)->where('content_type', $type)->where('content_id', $id)->exists()) {
            return true;
        }

        return DB::table('watch_position')->where('user_id', $userId)->where('movie_id', $id)->where('position', '>', 0)
            ->when($type === 'movie', fn ($query) => $query->where(fn ($q) => $q->whereNull('movie_type')->orWhere('movie_type', '!=', 'episode')),
                fn ($query) => $query->where('movie_type', 'episode'))->exists();
    }

    private function usable(object $user, array $candidate, CarbonImmutable $now): bool
    {
        if (CarbonImmutable::parse($candidate['eligible_at'], 'UTC')->gt($now) || ! $this->content($user, $candidate['content_type'], $candidate['content_id'])) {
            return false;
        }

        return ! DB::table('engagement_deliveries')->where('dedupe_key', $this->dedupe($user->user_id, $candidate))
            ->whereIn('status', ['sent', 'sending', 'failed'])->exists();
    }

    private function make(string $rule, string $type, string $id, string $at, CarbonImmutable $eligible, ?string $source = null): array
    {
        return ['rule' => $rule, 'content_type' => $type, 'content_id' => $id, 'source_key' => $source ?? $type.':'.$id,
            'source_at' => CarbonImmutable::parse($at, 'UTC')->utc()->toDateTimeString(), 'eligible_at' => $eligible->utc()->toDateTimeString()];
    }
}
