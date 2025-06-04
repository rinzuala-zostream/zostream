<?php

namespace App\Console\Commands;

use App\Http\Controllers\FCMNotificationController;
use App\Models\EpisodeModel;
use App\Models\MovieModel;
use Illuminate\Http\Request;
use Illuminate\Console\Command;

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
        $this->handleMovies();
        $this->handleEpisodes();
    }

    protected function handleMovies()
    {
        $movies = MovieModel::where('status', 'Scheduled')
            ->whereRaw("STR_TO_DATE(create_date, '%M %e, %Y') <= CURDATE()")
            ->get();


        if ($movies->isEmpty()) {
            $this->info('No scheduled movies to publish.');
        } else {
            MovieModel::whereIn('id', $movies->pluck('id'))->update(['status' => 'Published']);
            $this->info("Published {$movies->count()} movies.");

            foreach ($movies as $movie) {
                $this->sendNotification(
                    title: $movie->title,
                    body: 'Streaming now on Zo Stream',
                    image: $movie->cover_img ?? '',
                    key: $movie->id ?? '',
                );
            }
        }
    }

    protected function handleEpisodes()
    {
        // Load scheduled episodes with their related movie
        $episodes = EpisodeModel::where('status', 'Scheduled')
            ->whereRaw("STR_TO_DATE(create_date, '%M %e, %Y') <= CURDATE()")
            ->with('movie')
            ->get();


        if ($episodes->isEmpty()) {
            $this->info('No scheduled episodes to publish.');
        } else {
            EpisodeModel::whereIn('id', $episodes->pluck('id'))->update(['status' => 'Published']);
            $this->info("Published {$episodes->count()} episodes.");

            foreach ($episodes as $episode) {
                $movieTitle = $episode->movie->title ?? 'Unknown Movie';
                $movieImage = $episode->movie->cover_img ?? '';
                $movieKey = $episode->movie->id ?? '';

                $this->sendNotification(
                    title: "{$movieTitle} {$episode->txt}",
                    body: 'Test episode streaming on Zo Stream',
                    image: $movieImage,
                    key: $movieKey
                );
            }
        }
    }

    protected function sendNotification(string $title, string $body, string $image, string $key)
    {
        $fakeRequest = new Request([
            'title' => $title,
            'body' => $body,
            'image' => $image,
            'key' => $key
        ]);

        $this->fCMNotificationController->send($fakeRequest);
        $this->info("Notification sent: {$title}");
    }
}
