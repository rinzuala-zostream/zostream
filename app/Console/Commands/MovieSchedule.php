<?php

namespace App\Console\Commands;

use App\Http\Controllers\FCMNotificationController;
use App\Models\MovieModel;
use App\Models\New\Episode;
use Illuminate\Http\Request;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class MovieSchedule extends Command
{
    protected $signature = 'app:movie-schedule';

    protected $description = 'Update scheduled movies and episodes, and send FCM notifications.';

    protected $fCMNotificationController;

    public function __construct(FCMNotificationController $fCMNotificationController)
    {
        parent::__construct();
        $this->fCMNotificationController = $fCMNotificationController;
    }

    public function handle()
    {
        $movies = $this->handleMovies();
        $episodes = $this->handleEpisodes();

        $this->sendScheduleNotification($movies, $episodes);
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

    protected function sendScheduleNotification($movies, $episodes): void
    {
        $movieCount = $movies->count();
        $episodeCount = $episodes->count();

        if ($movieCount + $episodeCount === 0) {
            return;
        }

        if ($movieCount === 1 && $episodeCount === 0) {
            $movie = $movies->first();

            $this->sendNotification(
                title: $movie->title,
                body: 'Streaming now on Zo Stream',
                image: $movie->cover_img ?? '',
                key: $movie->id ?? '',
            );

            return;
        }

        if ($movieCount === 0 && $episodeCount === 1) {
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

        if ($episodeCount > 0) {
            $parts[] = "{$episodeCount} new " . ($episodeCount === 1 ? 'episode' : 'episodes');
        }

        $firstMovie = $movies->first();
        $firstEpisode = $episodes->first();

        $this->sendNotification(
            title: 'New on Zo Stream',
            body: implode(' and ', $parts) . ' streaming now',
            image: $firstMovie?->cover_img ?? $firstEpisode?->thumbnail ?? $firstEpisode?->season?->movie?->cover_img ?? '',
            key: $firstMovie?->id ?? $firstEpisode?->season?->movie?->id ?? '',
        );
    }

    protected function sendNotification(string $title, string $body, string $image, string $key)
    {
        $fakeRequest = new Request([
            'title' => $title,
            'body' => $body,
            'image' => $image,
            'key' => $key
        ]);

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
