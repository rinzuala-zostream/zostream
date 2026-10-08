<?php

namespace App\Http\Controllers\New;

use App\Http\Controllers\AdsController;
use App\Http\Controllers\Controller;
use App\Http\Controllers\LinkController;
use App\Http\Controllers\WatchPositionController;
use App\Models\MovieModel;
use App\Support\MizoOnlyContent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Str;
use Symfony\Component\HttpFoundation\Response as BaseResponse;

class DetailsController extends Controller
{
    private $validApiKey;

    protected $paymentStatusController;

    protected $deviceManagementController;

    protected $subscriptionController;

    protected $adsController;

    protected $calculatePlan;

    protected $linkController;

    protected $watchPositionController;

    protected $movieController;

    public function __construct(
        PaymentController $paymentStatusController,
        MovieController $movieController,
        SubscriptionController $subscriptionController,
        AdsController $adsController,
        LinkController $linkController,
        WatchPositionController $watchPositionController
    ) {
        $this->validApiKey = config('app.api_key');

        $this->movieController = $movieController;
        $this->paymentStatusController = $paymentStatusController;
        $this->subscriptionController = $subscriptionController;
        $this->adsController = $adsController;
        $this->linkController = $linkController;
        $this->watchPositionController = $watchPositionController;
    }

    public function getDetails(Request $request)
    {
        $apiKey = $request->header('X-Api-Key');

        $request->validate([
            'user_id' => 'required|string',
            'movie_id' => 'required|string',
            'device_id' => 'nullable|string',
            'device_type' => 'required|string',
            'type' => 'required|string|in:movie,episode',
        ]);

        $userId = $request->query('user_id');
        $movieId = $request->query('movie_id');
        $deviceId = $request->query('device_id');
        $deviceType = $request->query('device_type');
        $type = strtolower($request->query('type', 'movie'));

        $hasPlus = Str::contains($movieId, '_');

        if ($hasPlus) {
            $ids = explode('_', $movieId);
            $mainMovieId = $ids[0];
            $episodeId = $ids[1]; // handle if "_" is at the end accidentally
        } else {
            $mainMovieId = $movieId;
            $episodeId = $movieId;
        }

        if (MizoOnlyContent::appliesTo(MizoOnlyContent::userId($request))
            && ! $this->isMizoContent($type, $mainMovieId, $episodeId)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Content not found',
            ], 404);
        }

        try {
            // Call sub-controllers and decode JSON responses
            $paymentData = [
                'status' => 'skipped',
                'message' => 'Payment status is checked separately',
            ];

            if ($request->boolean('include_payment_status') && ! empty($deviceId)) {
                $paymentRequest = new Request(['user_id' => $userId, 'device_id' => $deviceId, 'device_type' => $deviceType]);
                $paymentRequest->headers->set('X-Api-Key', $apiKey);
                $paymentResponse = $this->paymentStatusController->processUserPayments($paymentRequest);
                $paymentData = json_decode($paymentResponse->getContent(), true);
            }

            $subscriptionRequest = new Request([
                'id' => $userId,
                'device_type' => $deviceType,
                'ip' => $request->query('ip'),
            ]);

            $subscriptionRequest->headers->set('X-Api-Key', $apiKey);

            $response = $this->subscriptionController->getByUser($subscriptionRequest, $userId);
            $subscriptionData = json_decode(json_encode($response->getData()), true);

            $adsRequest = new Request;
            $adsRequest->headers->set('X-Api-Key', $apiKey);
            $adsResponse = $this->adsController->getAds($adsRequest);
            $adsData = json_decode($adsResponse->getContent(), true);

            // Ads free logic
            if (isset($subscriptionData['status']) && $subscriptionData['status'] === 'error') {
                $subscriptionData['isAdsFree'] = empty($adsData);
            } else {
                $subscriptionData['isAdsFree'] = empty($adsData) || ($subscriptionData['isAdsFree'] ?? false);
            }

            // Get movie or episode
            $id = $type === 'movie' ? $mainMovieId : $episodeId;

            $movieRequest = new Request(['type' => $type]);
            $movieRequest->headers->set('X-Api-Key', $apiKey);

            $movieResponse = $this->movieController->getById($movieRequest, $id);
            $movie = json_decode($movieResponse->getContent(), true);

            if (! $movie) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No movie data found',
                    'error' => [
                        'mainMovieId' => $ids[0] ?? null,
                        'episodeId' => $ids[1] ?? null,
                    ],
                ], 404);
            }

            // Normalize booleans
            foreach ($movie as $key => $value) {
                if (is_numeric($value) && ($value == 0 || $value == 1)) {
                    $movie[$key] = (bool) $value;
                }
            }

            $movie['num'] = (int) ($movie['num'] ?? 0);
            $movie['views'] = (int) ($movie['views'] ?? 0);
            $movie['desc'] = $movie['desc'] ?? $movie['description'] ?? null;

            $isPpvEpisode = $type === 'episode'
                && (! empty($movie['isPayPerView']) || ! empty($movie['isPPV']));
            if ($isPpvEpisode || ! empty($movie['isPayPerView']) || ! empty($movie['isPPV']) || ($type === 'movie' && $this->hasPpvSeriesContent($movie['num']))) {
                $movie['views'] = 0;
            }

            // Ad display time
            if ($type === 'episode') {
                $url = ($movie['isProtected'] ?? false) ? ($movie['dash_url'] ?? null) : ($movie['url'] ?? null);

                if (! empty($url)) {
                    $duration = $this->getEpisodeDuration($url, $apiKey);
                    $ms = $this->convertToMilliseconds($duration);

                    if ($ms > 0) {
                        $movie['adDisplayTimes'] = ['second' => $ms / 2 + rand(1, $ms / 2)];
                    }
                }
            } elseif (! $subscriptionData['isAdsFree'] && ! empty($movie['duration'])) {
                $ms = $this->convertToMilliseconds($movie['duration']);
                $movie['adDisplayTimes'] = ['second' => $ms / 2 + rand(1, $ms / 2)];
            }

            // Determine age restriction as string ('true' or 'false')
            $isAgeRestricted = ! empty($movie['is_age_restricted']) && $movie['is_age_restricted'] ? 'true' : 'false';

            // Get user's watch position
            $watchRequest = new Request([
                'userId' => $userId,
                'movieId' => $movieId,
                'isAgeRestricted' => $isAgeRestricted,
            ]);
            $watchRequest->headers->set('X-Api-Key', $apiKey);

            $watchResponse = $this->watchPositionController->getWatchPosition($watchRequest);
            $watchData = json_decode($watchResponse->getContent(), true);

            $movie['watch_position'] = $watchData['watchPosition'] ?? 0;

            // getByUser() returns different shapes:
            // 1) with device_type: plain subscription object
            // 2) without device_type: { status, data: { data: [...] } } paginator
            $subscription = data_get($subscriptionData, 'data.data.0')
                ?? data_get($subscriptionData, 'data.0')
                ?? data_get($subscriptionData, 'data')
                ?? (is_array($subscriptionData) && isset($subscriptionData['id']) ? $subscriptionData : null);

            return response()->json([
                'subscription' => $subscription,
                'movie' => $movie,
                'ads' => $subscriptionData['isAdsFree'] ? [] : $adsData,
                'PaymentStatus' => $paymentData,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Internal server error',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function hasPpvSeriesContent(int $movieNum): bool
    {
        if ($movieNum <= 0) {
            return false;
        }

        return DB::table('episodes')
            ->join('seasons', 'seasons.id', '=', 'episodes.season_id')
            ->where('seasons.movie_id', $movieNum)
            ->where('seasons.season_number', 1)
            ->where('episodes.episode_number', 1)
            ->where('episodes.isPayPerView', true)
            ->exists();
    }

    private function isMizoContent(string $type, string $movieId, string $episodeId): bool
    {
        if ($type === 'movie') {
            return MovieModel::query()
                ->where('id', $movieId)
                ->where('isMizo', 1)
                ->exists();
        }

        return DB::table('episodes')
            ->join('seasons', 'seasons.id', '=', 'episodes.season_id')
            ->join('movie', 'movie.num', '=', 'seasons.movie_id')
            ->where(function ($query) use ($episodeId): void {
                $query->where('episodes.id', $episodeId);
                if (ctype_digit($episodeId)) {
                    $query->orWhere('episodes.num', (int) $episodeId);
                }
            })
            ->where('movie.isMizo', 1)
            ->exists();
    }

    private function convertToMilliseconds($duration)
    {
        $milliseconds = 0;
        preg_match_all('/(\d+)(h|m)/', $duration, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $milliseconds += ($match[2] === 'h') ? $match[1] * 3600000 : $match[1] * 60000;
        }

        return $milliseconds;
    }

    private function getEpisodeDuration(string $encryptedUrl, string $apiKey)
    {
        $payload = [
            'msg' => $encryptedUrl,
            'packageName' => 'com.buannel.studio.pvt.ltd.zostream',
            'sha' => 'd4c6198dabafb243b0d043a3c33a9fe171f81605158c267c7dfe5f66df29559a',
        ];

        $request = new Request($payload);
        $request->headers->set('X-Api-Key', $apiKey);

        $response = $this->linkController->decryptMessage($request);

        // Normalize response to an array
        if ($response instanceof JsonResponse) {
            $data = $response->getData(true); // associative array
        } elseif ($response instanceof BaseResponse) {
            $data = json_decode($response->getContent(), true) ?? [];
        } elseif (is_array($response)) {
            $data = $response;
        } else {
            // Unexpected return type
            return '0';
        }

        // Be tolerant of code being string or int
        $code = $data['code'] ?? null;
        if (! isset($data['message']) || ! in_array((string) $code, ['103'], true)) {
            return '0';
        }

        return $this->parseMPD($data['message'] ?? '');
    }

    private function parseMPD($mpdUrl)
    {
        try {
            $xml = simplexml_load_file(trim(str_replace(' ', '%20', $mpdUrl)));
            $duration = (string) $xml['mediaPresentationDuration'];

            return $this->formatDuration($this->parseISODuration($duration));
        } catch (\Exception $e) {
            return '0';
        }
    }

    private function parseISODuration($iso)
    {
        preg_match('/PT((\d+)H)?((\d+)M)?((\d+(\.\d+)?)S)?/', $iso, $m);
        $hours = isset($m[2]) ? (int) $m[2] : 0;
        $minutes = isset($m[4]) ? (int) $m[4] : 0;
        $seconds = isset($m[6]) ? round((float) $m[6]) : 0;

        return ($hours * 3600) + ($minutes * 60) + $seconds;
    }

    private function formatDuration($seconds)
    {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);

        return $hours > 0 ? "{$hours}h ".($minutes > 0 ? "{$minutes}m" : '') : "{$minutes}m";
    }
}
