<?php

namespace App\Http\Controllers;

use App\Models\AdsModel;
use App\Models\EpisodeModel;
use App\Models\MovieModel;
use App\Models\PPVPaymentModel;
use Http;
use Illuminate\Http\Request;

class DetailsController extends Controller
{
    private $validApiKey;
    protected $paymentStatusController;
    protected $deviceManagementController;
    protected $subscriptionController;
    protected $adsController;
    protected $calculatePlan;

    public function __construct(PaymentStatusController $paymentStatusController, 
    DeviceManagementController $deviceManagementController,
    SubscriptionController $subscriptionController,
    AdsController $adsModel,
    CalculatePlan $calculatePlan)
    
    {
        $this->validApiKey = config('app.api_key');
        $this->paymentStatusController = $paymentStatusController;
        $this->deviceManagementController = $deviceManagementController;
        $this->subscriptionController = $subscriptionController;
        $this->adsController = $adsModel;
        $this->calculatePlan = $calculatePlan;
     
    }

    public function getDetails(Request $request) {

        $apiKey = $request->header('X-Api-Key');

    if ($apiKey !== $this->validApiKey) {
        return response()->json(["status" => "error", "message" => "Invalid API key"], 401);
    }

    $request->validate([
        'user_id' => 'required|string',
        'movie_id' => 'required|string',
        'device_id' => 'required|string',
        'type' => 'required|string'
    ]);

    
    $userId = $request->query('user_id');
    $movieId = $request->query('movie_id');
    $deviceId = $request->query('device_id');
    $type = $request->query('type', 'movie');

    if (!$userId || !$movieId) {
        return response()->json([
            'status' => 'error',
            'message' => 'Missing required parameters: user_id or movie_id'
        ], 400);
    }

    try {

        $paymentStatus = new Request([
            'user_id' => $userId,
        ]);

        $subscriptionData = new Request([
            'id' => $userId,
        ]);

        $deviceDetails = new Request([
            'user_id' => $userId,
            'device_id' => $deviceId,
        ]);

        $adsData = new Request([
        ]);

        $paymentStatus->headers->set('X-Api-Key', $apiKey);
        $subscriptionData->headers->set('X-Api-Key', $apiKey);
        $deviceDetails->headers->set('X-Api-Key', $apiKey);
        $adsData->headers->set('X-Api-Key', $apiKey);

        $this->paymentStatusController->processUserPayments($paymentStatus);
        $this->subscriptionController->getSubscription($subscriptionData);
        $this->deviceManagementController->get($deviceDetails);
        $adsData = $this->adsController->getAds($adsData);

        $subscriptionData['deviceDetails'] = $deviceDetails;

        if (isset($subscriptionData['status']) && $subscriptionData['status'] === 'error') {
            $subscriptionData['isAdsFree'] = empty($adsData);
        } else {
            $subscriptionData['isAdsFree'] = empty($adsData) || ($subscriptionData['isAdsFree'] ?? false);
        }

        if ($type === 'movie') {
            $movie = MovieModel::where('id', $movieId)->first();
        } else {
            $movie = EpisodeModel::where('id', $movieId)->first();
        }

        if (!$movie) {
            return response()->json([
                'status' => 'error',
                'message' => 'No movie data found'
            ], 404);
        }

        $movie = (array) $movie;

        foreach ($movie as $key => $value) {
            if (is_numeric($value) && ($value == 0 || $value == 1)) {
                $movie[$key] = (bool) $value;
            }
        }

        $movie['num'] = (int) ($movie['num'] ?? 0);
        $movie['views'] = (int) ($movie['views'] ?? 0);

        $payPerViewKey = $movie['isPayPerView'] ?? $movie['isPPV'] ?? null;
        if ($payPerViewKey) {
            $movie['ppv_details'] = $this->fetchPPVDetails($userId, $movieId, $apiKey);
        }

        if ($type === 'episode') {
            $url = $movie['isProtected'] ? $movie['dash_url'] : $movie['url'];
            $duration = $this->getEpisodeDuration($url);
            $ms = $this->convertToMilliseconds($duration);
            $movie['adDisplayTimes'] = ['second' => $ms / 2 + rand(1, $ms / 2)];
        } else if (!$subscriptionData['isAdsFree'] && !empty($movie['duration'])) {
            $ms = $this->convertToMilliseconds($movie['duration']);
            $movie['adDisplayTimes'] = ['second' => $ms / 2 + rand(1, $ms / 2)];
        }

        return response()->json([
            'subscription' => $subscriptionData,
            'movie' => $movie,
            'ads' => $subscriptionData['isAdsFree'] ? [] : $adsData,
            'PaymentStatus' => $paymentStatus,
        ]);

    } catch (\Exception $e) {
        return response()->json([
            'status' => 'error',
            'message' => 'Internal server error',
            'error' => $e->getMessage()
        ], 500);
    }

    }

    private function fetchPPVDetails($userId, $movieId, $apiKey)
    {
        $ppvData = PPVPaymentModel::where('user_id', $userId)
            ->where('movie_id', $movieId)
            ->first();

        if (!$ppvData) {
            return [
                'isRented' => false,
                'rentalPurchased' => null,
                'rentalExpiry' => null,
                'daysLeft' => 0
            ];
        }

        $purchaseDate = \Carbon\Carbon::parse($ppvData->purchase_date)->format('Y-m-d');
        $period = $ppvData->rental_period;

        $response = new Request([
            'period' => $period,
            'current_date' => $purchaseDate,
        ]);
        $response->headers->set('X-Api-Key', $apiKey);

        $this->calculatePlan->calculate($response);

        if (!isset($response['data']['expiry_date'])) {
            return [
                'isRented' => false,
                'rentalPurchased' => null,
                'rentalExpiry' => null,
                'daysLeft' => 0
            ];
        }

        $expiry = \Carbon\Carbon::parse($response['data']['expiry_date']);
        $now = now();
        $daysLeft = $now->diffInDays($expiry, false);
        $isRented = $now->between(\Carbon\Carbon::parse($purchaseDate), $expiry);

        return [
            'isRented' => $isRented,
            'rentalPurchased' => \Carbon\Carbon::parse($purchaseDate)->format('F j, Y'),
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
