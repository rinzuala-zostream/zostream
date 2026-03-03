<?php

namespace App\Http\Controllers\New;

use App\Http\Controllers\AdsController;
use App\Http\Controllers\Controller;
use App\Http\Controllers\DeviceManagementController;
use App\Http\Controllers\LinkController;
use App\Http\Controllers\PaymentStatusController;
use App\Http\Controllers\WatchPositionController;
use App\Models\AdsModel;
use App\Models\EpisodeModel;
use App\Models\MovieModel;
use App\Models\New\PaymentHistory;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Str;
use Symfony\Component\HttpFoundation\Response as BaseResponse;
use Illuminate\Support\Facades\Http;
use Carbon\Carbon;

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
            'type' => 'required|string'
        ]);

        $userId = $request->query('user_id');
        $movieId = $request->query('movie_id');
        $deviceId = $request->query('device_id');
        $deviceType = $request->query('device_type');
        $type = $request->query('type', 'movie');

        $hasPlus = Str::contains($movieId, '_');

        if ($hasPlus) {
            $ids = explode('_', $movieId);
            $mainMovieId = $ids[0];
            $episodeId = $ids[1]; // handle if "_" is at the end accidentally
        } else {
            $mainMovieId = $movieId;
            $episodeId = $movieId;
        }

        try {
            // Call sub-controllers and decode JSON responses
            $paymentRequest = new Request(['user_id' => $userId, 'device_id' => $deviceId, 'device_type' => $deviceType]);
            $paymentRequest->headers->set('X-Api-Key', $apiKey);
            $paymentResponse = $this->paymentStatusController->processUserPayments($paymentRequest);
            $paymentData = json_decode($paymentResponse->getContent(), true);

            $subscriptionRequest = new Request([
                'id' => $userId,
                'device_type' => $deviceType,
                'ip' => $request->query('ip')
            ]);

            $subscriptionRequest->headers->set('X-Api-Key', $apiKey);

            $response = $this->subscriptionController->getByUser($subscriptionRequest, $userId);
            $subscriptionData = json_decode(json_encode($response->getData()), true);

            $adsRequest = new Request();
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

            if (!$movie) {
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

            // PPV logic
            $ppvKey = $movie['isPayPerView'] ?? $movie['isPPV'] ?? null;
            if ($ppvKey) {
                $movie['ppv_details'] = $this->fetchPPVDetails($userId, $movieId, $deviceType);
            }

            // Ad display time
            if ($type === 'episode') {
                $url = $movie['isProtected'] ? $movie['dash_url'] : $movie['url'];
                $duration = $this->getEpisodeDuration($url, $apiKey);
                $ms = $this->convertToMilliseconds($duration);
                $movie['adDisplayTimes'] = ['second' => $ms / 2 + rand(1, $ms / 2)];
            } elseif (!$subscriptionData['isAdsFree'] && !empty($movie['duration'])) {
                $ms = $this->convertToMilliseconds($movie['duration']);
                $movie['adDisplayTimes'] = ['second' => $ms / 2 + rand(1, $ms / 2)];
            }

            // Determine age restriction as string ('true' or 'false')
            $isAgeRestricted = !empty($movie['is_age_restricted']) && $movie['is_age_restricted'] ? 'true' : 'false';

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

            return response()->json([
                'subscription' => data_get($subscriptionData, 'data.data')[0] ?? null,
                'movie' => $movie,
                'ads' => $subscriptionData['isAdsFree'] ? [] : $adsData,
                'PaymentStatus' => $paymentData,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Internal server error',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    private function fetchPPVDetails($userId, $movieId, $deviceType)
    {
        $hasPlus = strpos($movieId, '_') !== false;

        if ($hasPlus) {
            list($mainMovieId, $episodeId) = explode('_', $movieId, 2);
            $mainMovieId = trim($mainMovieId);
            $episodeId = trim($episodeId);
            if ($episodeId === '') {
                $episodeId = null;
            }
        } else {
            $mainMovieId = trim($movieId);
            $episodeId = null;
        }

        $candidates = PaymentHistory::where('user_id', $userId)
            ->where('status', 'success')
            ->where('device_type', strtolower(trim($deviceType))) // 🔥 DB level filter
            ->where(function ($q) {
                $q->whereRaw('LOWER(COALESCE(app_payment_type, "")) = ?', ['ppv'])
                    ->orWhereRaw('LOWER(COALESCE(payment_type, "")) = ?', ['ppv']);
            })
            ->orderBy('id', 'desc')
            ->get();

        $ppvData = $candidates->first(function ($payment) use ($mainMovieId, $episodeId, $deviceType) {

            $meta = is_array($payment->meta) ? $payment->meta : [];

            $paidMovieId = (string) ($meta['movie_id'] ?? $meta['content_id'] ?? '');

            // 🔥 STRICT DEVICE MATCH (from DB column)
            $paidDeviceType = strtolower(trim((string) $payment->device_type));
            $requestedDeviceType = strtolower(trim((string) $deviceType));

            $movieMatch = $paidMovieId !== '' && (
                $paidMovieId === (string) $mainMovieId ||
                ($episodeId !== null && $paidMovieId === (string) $episodeId)
            );

            $deviceMatch = $paidDeviceType === $requestedDeviceType;

            return $movieMatch && $deviceMatch;
        });

        if (!$ppvData) {
            return [
                'isRented' => false,
                'rentalPurchased' => null,
                'rentalExpiry' => null,
                'daysLeft' => 0
            ];
        }

        $meta = is_array($ppvData->meta) ? $ppvData->meta : [];
        $purchaseAt = $ppvData->payment_date ?? $ppvData->created_at ?? now();
        $purchaseDate = Carbon::parse($purchaseAt);

        if (!empty($ppvData->expiry_date)) {
            $expiry = Carbon::parse($ppvData->expiry_date);
        } else {
            $period = (int) ($meta['rental_period'] ?? $meta['period'] ?? 0);
            if ($period <= 0) {
                $period = 7;
            }
            $expiry = $purchaseDate->copy()->addDays($period);
        }

        $now = now();
        $daysLeft = (int) $now->diffInDays($expiry, false);
        $isRented = $now->between($purchaseDate, $expiry);

        return [
            'isRented' => $isRented,
            'rentalPurchased' => $purchaseDate->format('F j, Y'),
            'rentalExpiry' => $expiry->format('F j, Y'),
            'daysLeft' => $daysLeft > 0 ? "Ni $daysLeft chhung ila en thei." : "Vawiin chiah i en thei tawh",
        ];
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
            return "0";
        }

        // Be tolerant of code being string or int
        $code = $data['code'] ?? null;
        if (!isset($data['message']) || !in_array((string) $code, ['103'], true)) {
            return "0";
        }

        return $this->parseMPD($data['message'] ?? '');
    }

    private function parseMPD($mpdUrl)
    {
        try {
            $xml = simplexml_load_file(trim(str_replace(" ", "%20", $mpdUrl)));
            $duration = (string) $xml['mediaPresentationDuration'];
            return $this->formatDuration($this->parseISODuration($duration));
        } catch (\Exception $e) {
            return "0";
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
        return $hours > 0 ? "{$hours}h " . ($minutes > 0 ? "{$minutes}m" : "") : "{$minutes}m";
    }
}
