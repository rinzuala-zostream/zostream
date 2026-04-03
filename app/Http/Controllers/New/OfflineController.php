<?php

namespace App\Http\Controllers\New;

use App\Http\Controllers\Controller;
use App\Http\Controllers\HlsFolderController;
use App\Models\New\Devices;
use App\Models\New\Plan;
use App\Models\New\Subscription;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Str;

class OfflineController extends Controller
{

    public $movieController;
    public $hlsFolderController;

    public function __construct(HlsFolderController $hlsFolderController, MovieController $movieController)
    {
        $this->hlsFolderController = $hlsFolderController;
        $this->movieController = $movieController;
    }
    public function requestOffline(Request $request)
    {
        $movieId = $request->get('movie_id');
        $subscriptionId = $request->get('subscription_id');
        $platform = $request->get('platform');
        $movieType = $request->get('movie_type', 'movie'); // Default to movie
        $deviceToken = $request->get('device_token');
        $userId = $request->get('user_id');
        $mpdUrl = null;

        if (!$movieId || !$subscriptionId) {
            return response()->json(['status' => 'error', 'message' => 'Parameters required'], 400);
        }

        $deviceQuery = Devices::where('device_token', $deviceToken)
            ->where('user_id', $userId);

        $device = $deviceQuery->first();

        if (!$device) {
            return response()->json([
                'status' => 'error',
                'title' => 'Device Not Recognized',
                'message' => 'We couldn’t verify this device or your device has no subscription. Please sign in again or contact support if the issue continues.'
            ], 404);
        }

        // 🔥 Default values for PPV
        $subscription = null;
        $plan = null;
        $type = strtolower(trim((string) $device->device_type));


        // 2️⃣ Subscription check
        $subscription = Subscription::find($subscriptionId);

        if (!$subscription) {
            return response()->json([
                'status' => 'error',
                'title' => 'Subscription Not Found',
                'message' => 'We could not find an active subscription for your account. Please check your subscription status.'
            ], 404);
        }

        if (Carbon::parse($subscription->end_at)->isPast() || !$subscription->is_active) {
            return response()->json([
                'status' => 'error',
                'title' => 'Subscription Expired',
                'message' => 'Your subscription has expired. Please renew your plan to continue watching.'
            ], 403);
        }

        // 3️⃣ Plan check
        $plan = Plan::find($subscription->plan_id);

        if (!$plan) {
            return response()->json([
                'status' => 'error',
                'title' => 'Plan Information Unavailable',
                'message' => 'We’re unable to retrieve your subscription plan right now. Please try again later.'
            ], 500);
        }

        $planType = strtolower(trim((string) $plan->device_type));

        if ($planType !== $type) {
            return response()->json([
                'status' => 'error',
                'title' => 'Invalid Plan for This Device',
                'message' => 'Your current subscription does not support this device type.'
            ], 403);
        }

        $req = new Request();
        $req->merge(['type' => $movieType]);

        $movieResponse = $this->movieController->getLink($req, $movieId);
        $movieData = $movieResponse->getData(true);

        if (($movieData['status'] ?? null) === 'success') {

            $links = $movieData['links'] ?? [];

            if ($platform === 'ios') {

                $hlsUrl = $links['url'] ?? null;

                if ($hlsUrl) {
                    $fakeReq = new Request();
                    $fakeReq->merge(['url' => $hlsUrl]);

                    $hlsResponse = $this->hlsFolderController->check($fakeReq);
                    $hlsData = $hlsResponse->getData(true);

                    $mpdUrl = $hlsData['data']['stream_url'] ?? null;

                }

            } else {

                $dashUrl = $links['url'] ?? null;

                if ($dashUrl) {

                    $mpdUrl = $this->resolveMpdUrl($dashUrl)['url'];


                }
            }
        }

        try {
            // Fetch MPD content
            $mpdContent = file_get_contents($mpdUrl);

            if (!$mpdContent) {
                return response()->json(['status' => 'error', 'message' => 'Unable to fetch MPD'], 500);
            }

            // Parse XML
            $xml = simplexml_load_string($mpdContent);

            $qualities = [];
            $trackIndex = 0;

            foreach ($xml->Period->AdaptationSet as $adaptationSet) {

                // Only video tracks
                if ((string) $adaptationSet['mimeType'] !== 'video/mp4') {
                    continue;
                }

                foreach ($adaptationSet->Representation as $rep) {

                    $height = (int) $rep['height'];
                    $bandwidth = (int) $rep['bandwidth'];

                    if ($height > 0) {
                        $qualities[] = [
                            'label' => $height . 'p',
                            'height' => $height,
                            'bitrate' => $bandwidth,
                            'rep_id' => (string) $rep['id']
                        ];

                        $trackIndex++;
                    }
                }
            }

            // Sort by quality (low → high)
            usort($qualities, function ($a, $b) {
                return $a['height'] <=> $b['height'];
            });

            return response()->json([
                'video_url' => $mpdUrl,
                'qualities' => $qualities
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to parse MPD',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    private function resolveMpdUrl(string $raw): array
    {
        // Case 1: Plain MPD URL
        if (Str::contains($raw, 'http') && Str::contains($raw, 'mpd')) {
            return [
                'url' => $raw,
                'source' => 'plaintext'
            ];
        }

        // Try decrypt
        $rawParam = str_replace(' ', '+', $raw);

        $shaKey = 'd4c6198dabafb243b0d043a3c33a9fe171f81605158c267c7dfe5f66df29559a';

        // AES-256 key
        $decryptionKey = hash('sha256', $shaKey, true);

        // ---- Flexible Base64 decode ----
        $b64 = strtr($rawParam, '-_', '+/');
        $pad = strlen($b64) % 4;

        if ($pad) {
            $b64 .= str_repeat('=', 4 - $pad);
        }

        $data = @base64_decode($b64, true);

        if ($data === false || strlen($data) < 17) {
            throw new \Exception('Invalid encrypted payload.');
        }

        // Extract IV + ciphertext
        $iv = substr($data, 0, 16);
        $cipherText = substr($data, 16);

        if (strlen($iv) !== 16 || $cipherText === '') {
            throw new \Exception('Corrupt encrypted payload.');
        }

        $decryptedMessage = openssl_decrypt(
            $cipherText,
            'aes-256-cbc',
            $decryptionKey,
            OPENSSL_RAW_DATA,
            $iv
        );

        if ($decryptedMessage === false) {
            throw new \Exception('Decryption failed.');
        }

        // Normalize
        $result = trim(str_replace(["\r", "\n"], '', $decryptedMessage));

        $maybeUrl = filter_var($result, FILTER_VALIDATE_URL)
            ? $result
            : urldecode($result);

        if (stripos($maybeUrl, '.mpd') === false) {
            throw new \Exception('Decrypted URL is not an MPD manifest.');
        }

        return [
            'url' => $maybeUrl,
            'source' => 'decrypted'
        ];
    }
}

