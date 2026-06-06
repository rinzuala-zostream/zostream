<?php

namespace App\Console\Commands;

use App\Http\Controllers\FCMNotificationController;
use App\Models\MovieModel;
use App\Models\New\Episode;
use App\Models\New\Season;
use Illuminate\Http\Request;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class MovieSchedule extends Command
{
    protected $signature = 'app:movie-schedule';

    protected $description = 'Update scheduled movies, seasons, and episodes, and send FCM notifications.';

    protected $fCMNotificationController;

    public function __construct(FCMNotificationController $fCMNotificationController)
    {
        parent::__construct();
        $this->fCMNotificationController = $fCMNotificationController;
    }

    public function handle()
    {
        $movies = $this->handleMovies();
        $seasons = $this->handleSeasons();
        $episodes = $this->handleEpisodes();

        $this->sendScheduleNotification($movies, $seasons, $episodes);
    }

    protected function handleMovies()
    {
        $movies = MovieModel::where('status', 'Scheduled')
            ->orderBy('num')
            ->get()
            ->filter(fn ($movie) => $this->isDue($movie->create_date))
            ->values();


        if ($movies->isEmpty()) {
            $this->info('No scheduled movies to publish.');
        } else {
            MovieModel::whereIn('id', $movies->pluck('id'))->update(['status' => 'Published']);
            $this->info("Published {$movies->count()} movies.");
        }

        return $movies;
    }

    protected function handleSeasons()
    {
        $seasons = Season::where('status', 'Scheduled')
            ->with('movie')
            ->orderBy('num')
            ->get()
            ->filter(fn ($season) => $this->isDue($season->release_date))
            ->values();


        if ($seasons->isEmpty()) {
            $this->info('No scheduled seasons to publish.');
        } else {
            Season::whereIn('id', $seasons->pluck('id'))->update(['status' => 'Published']);
            $this->info("Published {$seasons->count()} seasons.");
        }

        return $seasons;
    }

    protected function handleEpisodes()
    {
        $episodes = Episode::where('status', 'Scheduled')
            ->with('season.movie')
            ->orderBy('num')
            ->get()
            ->filter(fn ($episode) => $this->isDue($episode->release_date))
            ->values();


        if ($episodes->isEmpty()) {
            $this->info('No scheduled episodes to publish.');
        } else {
            Episode::whereIn('id', $episodes->pluck('id'))->update(['status' => 'Published']);
            $this->info("Published {$episodes->count()} episodes.");
        }

        return $episodes;
    }

    protected function sendScheduleNotification($movies, $seasons, $episodes): void
    {
        $movieCount = $movies->count();
        $seasonCount = $seasons->count();
        $episodeCount = $episodes->count();

        if ($movieCount + $seasonCount + $episodeCount === 0) {
            return;
        }

        if ($movieCount === 1 && $seasonCount === 0 && $episodeCount === 0) {
            $movie = $movies->first();

            $this->sendNotification(
                title: $movie->title,
                body: 'Streaming now on Zo Stream',
                image: $movie->cover_img ?? '',
                key: $movie->id ?? '',
            );

            return;
        }

        if ($movieCount === 0 && $seasonCount === 1 && $episodeCount === 0) {
            $season = $seasons->first();
            $movie = $season->movie;
            $title = trim(($movie?->title ?? 'New Season') . ' ' . ($season->title ?? ''));

            $this->sendNotification(
                title: $title,
                body: 'New season streaming on Zo Stream',
                image: $season->poster ?? $movie?->cover_img ?? '',
                key: $movie?->id ?? '',
            );

            return;
        }

        if ($movieCount === 0 && $seasonCount === 0 && $episodeCount === 1) {
            $episode = $episodes->first();
            $movie = $episode->season?->movie;
            $movieTitle = $movie?->title ?? 'Unknown Movie';

            $this->sendNotification(
                title: "{$movieTitle} {$episode->title}",
                body: 'New episode streaming on Zo Stream',
                image: $episode->thumbnail ?? $movie?->cover_img ?? '',
                key: $movie?->id ?? '',
            );

            return;
        }

        $parts = [];

        if ($movieCount > 0) {
            $parts[] = "{$movieCount} new " . ($movieCount === 1 ? 'movie' : 'movies');
        }

        if ($seasonCount > 0) {
            $parts[] = "{$seasonCount} new " . ($seasonCount === 1 ? 'season' : 'seasons');
        }

        if ($episodeCount > 0) {
            $parts[] = "{$episodeCount} new " . ($episodeCount === 1 ? 'episode' : 'episodes');
        }

        $this->sendNotification(
            title: 'New on Zo Stream',
            body: implode(' and ', $parts) . ' streaming now',
        );
    }

    protected function sendNotification(string $title, string $body, ?string $image = null, ?string $key = null)
    {
        $payload = [
            'title' => $title,
            'body' => $body,
        ];

        if (!empty($image)) {
            $payload['image'] = $image;
        }

        if (!empty($key)) {
            $payload['key'] = $key;
        }

        $fakeRequest = new Request($payload);

        try {
            $result = $this->fCMNotificationController->send($fakeRequest);
            $status = is_array($result) ? ($result['status'] ?? 'unknown') : 'unknown';
            $this->info("Notification sent: {$title} (status: {$status})");
        } catch (\Exception $e) {
            $this->error("Notification failed: {$title} - {$e->getMessage()}");
        }
    }

    private function isDue($date): bool
    {
        if (empty($date)) {
            return false;
        }

        try {
            return Carbon::parse($date)->startOfDay()->lte(today());
        } catch (\Throwable) {
            $this->warn("Invalid schedule date skipped: {$date}");
            return false;
        }
    }
}
