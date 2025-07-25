<?php

namespace App\Http\Controllers;

use App\Models\AdsModel;
use App\Models\EpisodeModel;
use App\Models\MovieModel;
use App\Models\PPVPaymentModel;
use Illuminate\Http\Request;
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

    public function __construct(
        PaymentStatusController $paymentStatusController,
        DeviceManagementController $deviceManagementController,
        SubscriptionController $subscriptionController,
        AdsController $adsController,
        CalculatePlan $calculatePlan
    ) {
        $this->validApiKey = config('app.api_key');
        $this->paymentStatusController = $paymentStatusController;
        $this->deviceManagementController = $deviceManagementController;
        $this->subscriptionController = $subscriptionController;
        $this->adsController = $adsController;
        $this->calculatePlan = $calculatePlan;
    }

    public function getDetails(Request $request)
    {
        $apiKey = $request->header('X-Api-Key');
        if ($apiKey !== $this->validApiKey) {
            return response()->json(["status" => "error", "message" => "Invalid API key"], 401);
        }

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

        try {
            // Call sub-controllers and decode JSON responses
            $paymentRequest = new Request(['user_id' => $userId]);
            $paymentRequest->headers->set('X-Api-Key', $apiKey);
            $paymentResponse = $this->paymentStatusController->processUserPayments($paymentRequest);
            $paymentData = json_decode($paymentResponse->getContent(), true);

            $subscriptionRequest = new Request([
                'id' => $userId,
                'device_type' => $deviceType,
                'ip' => $request->query('ip')
            ]);

            $subscriptionRequest->headers->set('X-Api-Key', $apiKey);

            $response = $this->subscriptionController->getSubscription($subscriptionRequest);
            $subscriptionData = json_decode(json_encode($response->getData()), true);

            $deviceRequest = new Request(['user_id' => $userId, 'device_id' => $deviceId]);
            $deviceRequest->headers->set('X-Api-Key', $apiKey);
            $deviceResponse = $this->deviceManagementController->get($deviceRequest);
            $deviceData = json_decode($deviceResponse->getContent(), true);

            $adsRequest = new Request();
            $adsRequest->headers->set('X-Api-Key', $apiKey);
            $adsResponse = $this->adsController->getAds($adsRequest);
            $adsData = json_decode($adsResponse->getContent(), true);

            // Set device details in subscription
            $subscriptionData['deviceDetails'] = $deviceData;

            // Ads free logic
            if (isset($subscriptionData['status']) && $subscriptionData['status'] === 'error') {
                $subscriptionData['isAdsFree'] = empty($adsData);
            } else {
                $subscriptionData['isAdsFree'] = empty($adsData) || ($subscriptionData['isAdsFree'] ?? false);
            }

            // Get movie or episode
            $movie = $type === 'movie'
                ? MovieModel::where('id', $movieId)->first()
                : EpisodeModel::where('id', $movieId)->first();

            if (!$movie) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No movie data found'
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
                $movie['ppv_details'] = $this->fetchPPVDetails($userId, $movieId, $apiKey, $deviceType);
            }

            // Ad display time
            if ($type === 'episode') {
                $url = $movie['isProtected'] ? $movie['dash_url'] : $movie['url'];
                $duration = $this->getEpisodeDuration($url);
                $ms = $this->convertToMilliseconds($duration);
                $movie['adDisplayTimes'] = ['second' => $ms / 2 + rand(1, $ms / 2)];
            } elseif (!$subscriptionData['isAdsFree'] && !empty($movie['duration'])) {
                $ms = $this->convertToMilliseconds($movie['duration']);
                $movie['adDisplayTimes'] = ['second' => $ms / 2 + rand(1, $ms / 2)];
            }

            return response()->json([
                'subscription' => $subscriptionData,
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

    private function fetchPPVDetails($userId, $movieId, $apiKey, $deviceType)
    {
        $ppvData = PPVPaymentModel::where('user_id', $userId)
            ->where('movie_id', $movieId)
            ->where('platform', $deviceType)
            ->orderBy('id', 'desc')
            ->first();

        if (!$ppvData) {
            return [
                'isRented' => false,
                'rentalPurchased' => null,
                'rentalExpiry' => null,
                'daysLeft' => 0
            ];
        }

        $purchaseDate = Carbon::parse($ppvData->purchase_date)->format('Y-m-d');
        $period = $ppvData->rental_period;

        $response = new Request([
            'period' => $period,
            'current_date' => $purchaseDate,
        ]);
        $response->headers->set('X-Api-Key', $apiKey);

        $calculateResponse = $this->calculatePlan->calculate($response);
        $calculateData = json_decode($calculateResponse->getContent(), true);

        if (!isset($calculateData['data']['expiry_date'])) {
            return [
                'isRented' => false,
                'rentalPurchased' => null,
                'rentalExpiry' => null,
                'daysLeft' => 0
            ];
        }

        $expiry = Carbon::parse($calculateData['data']['expiry_date']);
        $now = now();
        $daysLeft = (int) $now->diffInDays($expiry, false);
        $isRented = $now->between(Carbon::parse($purchaseDate), $expiry);

        return [
            'isRented' => $isRented,
            'rentalPurchased' => Carbon::parse($purchaseDate)->format('F j, Y'),
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

    private function getEpisodeDuration($encryptedUrl)
    {
        $payload = [
            'msg' => $encryptedUrl,
            'packageName' => 'com.buannel.studio.pvt.ltd.zostream',
            'sha' => 'd4c6198dabafb243b0d043a3c33a9fe171f81605158c267c7dfe5f66df29559a'
        ];

        $response = Http::timeout(10)
            ->withHeaders(['Content-Type' => 'application/json'])
            ->post('https://api.zostream.in/link_check.php', $payload);

        $data = $response->json();

        if (!isset($data['response']) || $data['code'] !== '103') {
            return "0";
        }

        return $this->parseMPD($data['response']);
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
