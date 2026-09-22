<?php

namespace App\Http\Controllers\New;

use App\Http\Controllers\Controller;
use App\Http\Controllers\HlsFolderController;
use App\Models\MovieModel;
use App\Models\New\Devices;
use App\Models\New\Episode;
use App\Models\New\PaymentHistory;
use App\Models\New\Plan;
use App\Models\New\Subscription;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

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
        $rawSubscriptionId = $request->get('subscription_id');
        $subscriptionId = filled($rawSubscriptionId) && (int) $rawSubscriptionId > 0
            ? (int) $rawSubscriptionId
            : null;
        $platform = strtolower(trim((string) $request->get('platform')));
        $movieType = strtolower(trim((string) $request->get('movie_type', 'movie')));
        $deviceToken = $request->get('device_token');
        $userId = $request->get('user_id');

        if (! $movieId || ! in_array($movieType, ['movie', 'episode'], true)) {
            return response()->json(['status' => 'error', 'message' => 'A valid movie or episode is required.'], 422);
        }

        $content = $this->findContent($movieType, (string) $movieId);

        if (! $content) {
            return response()->json([
                'status' => 'error',
                'message' => 'The requested movie or episode is unavailable.',
            ], 404);
        }

        $isPayPerView = (bool) ($content->isPayPerView ?? false);
        $requiresSubscription = (bool) ($content->isPremium ?? false) && ! $isPayPerView;

        $deviceQuery = Devices::where('device_token', $deviceToken)
            ->where('user_id', $userId);

        $device = $deviceQuery->first();

        if (! $device) {
            return response()->json([
                'status' => 'error',
                'title' => 'Device Not Recognized',
                'message' => 'We couldn’t verify this device. Please sign in again or contact support if the issue continues.',
            ], 404);
        }

        $type = strtolower(trim((string) $device->device_type));

        if ($isPayPerView && ! $this->hasActiveRental($userId, $device, $content, $movieType)) {
            return response()->json([
                'status' => 'error',
                'title' => 'Rental Required',
                'message' => 'Please rent this content before downloading it.',
            ], 403);
        }

        if ($requiresSubscription) {
            if (! $subscriptionId) {
                return response()->json([
                    'status' => 'error',
                    'title' => 'Subscription Required',
                    'message' => 'An active subscription is required to download this content.',
                ], 403);
            }

            $subscription = Subscription::find($subscriptionId);

            if (! $subscription) {
                return response()->json([
                    'status' => 'error',
                    'title' => 'Subscription Not Found',
                    'message' => 'We could not find an active subscription for your account. Please check your subscription status.',
                ], 404);
            }

            if (! hash_equals((string) $subscription->user_id, (string) $userId)) {
                return response()->json([
                    'status' => 'error',
                    'title' => 'Subscription Access Denied',
                    'message' => 'This subscription does not belong to your account.',
                ], 403);
            }

            if (Subscription::endAtIsExpired($subscription->end_at) || ! $subscription->is_active) {
                return response()->json([
                    'status' => 'error',
                    'title' => 'Subscription Expired',
                    'message' => 'Your subscription has expired. Please renew your plan to continue watching.',
                ], 403);
            }

            $plan = Plan::find($subscription->plan_id);

            if (! $plan) {
                return response()->json([
                    'status' => 'error',
                    'title' => 'Plan Information Unavailable',
                    'message' => 'We’re unable to retrieve your subscription plan right now. Please try again later.',
                ], 500);
            }

            $planType = strtolower(trim((string) $plan->device_type));

            if ($planType !== $type) {
                return response()->json([
                    'status' => 'error',
                    'title' => 'Invalid Plan for This Device',
                    'message' => 'Your current subscription does not support this device type.',
                ], 403);
            }

            if ((int) $device->subscription_id !== $subscriptionId) {
                return response()->json([
                    'status' => 'error',
                    'title' => 'Device Access Changed',
                    'message' => 'This device is not linked to the selected subscription.',
                ], 403);
            }
        }

        $req = Request::create('', 'GET', ['type' => $movieType]);

        $movieResponse = $this->movieController->getLink($req, $movieId);
        $movieData = $movieResponse->getData(true);

        if (($movieData['status'] ?? null) !== 'success') {
            return response()->json([
                'status' => 'error',
                'message' => $movieData['message'] ?? 'A downloadable stream is not available for this content.',
            ], $movieResponse->getStatusCode());
        }

        $sourceUrl = $this->selectSourceUrl($movieData['links'] ?? [], $platform);

        if (! $sourceUrl) {
            return response()->json([
                'status' => 'error',
                'message' => 'A downloadable stream is not available for this content.',
            ], 404);
        }

        if ($platform === 'ios') {
            return $this->iosOfflineResponse($sourceUrl, $movieId, $movieType);
        }

        try {
            $mpdUrl = $this->resolveMpdUrl($sourceUrl)['url'];

            // Fetch MPD content
            $mpdContent = file_get_contents($mpdUrl);

            if (! $mpdContent) {
                return response()->json(['status' => 'error', 'message' => 'Unable to fetch MPD'], 500);
            }

            // Parse XML
            $xml = simplexml_load_string($mpdContent);

            if ($xml === false) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'The DASH manifest is invalid.',
                ], 502);
            }

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
                            'label' => $height.'p',
                            'height' => $height,
                            'bitrate' => $bandwidth,
                            'rep_id' => (string) $rep['id'],
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
                'qualities' => $qualities,
                'format' => 'dash',
                'content_id' => (string) $movieId,
                'content_type' => $movieType,
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to parse MPD',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function iosOfflineResponse(string $sourceUrl, $movieId, string $movieType)
    {
        $hlsUrl = Str::contains(strtolower($sourceUrl), 'm3u8') ? $sourceUrl : null;

        if (! $hlsUrl) {
            $hlsRequest = Request::create('', 'GET', ['url' => $sourceUrl]);
            $hlsResponse = $this->hlsFolderController->check($hlsRequest);
            $hlsData = $hlsResponse->getData(true);
            $hlsUrl = $hlsData['data']['stream_url'] ?? null;

            if (($hlsData['status'] ?? null) !== 'success' || ! $hlsUrl) {
                return response()->json([
                    'status' => 'error',
                    'message' => $hlsData['message'] ?? 'Unable to prepare the HLS download.',
                ], $hlsResponse->getStatusCode() >= 400 ? $hlsResponse->getStatusCode() : 500);
            }
        }

        // AVAssetDownloadURLSession consumes the HLS master playlist directly.
        // Do not pass it through the DASH/XML quality parser below.
        return response()->json([
            'video_url' => $hlsUrl,
            'qualities' => [],
            'format' => 'hls',
            'content_id' => (string) $movieId,
            'content_type' => $movieType,
        ]);
    }

    private function selectSourceUrl($links, string $platform): ?string
    {
        if (! is_array($links)) {
            return null;
        }

        // Movie format: links => ['url' => '...', 'hls_url' => '...', 'dash_url' => '...']
        if (isset($links['url']) || isset($links['hls_url']) || isset($links['dash_url'])) {
            $url = $platform === 'ios'
                ? ($links['hls_url'] ?? $links['url'] ?? $links['dash_url'] ?? null)
                : ($links['dash_url'] ?? $links['url'] ?? $links['hls_url'] ?? null);

            return is_string($url) && trim($url) !== '' ? trim($url) : null;
        }

        // Episode format: links => [['url' => '...'], ...]
        foreach ($links as $item) {
            $url = is_array($item) ? ($item['url'] ?? null) : null;

            if (is_string($url) && trim($url) !== '') {
                return trim($url);
            }
        }

        return null;
    }

    private function findContent(string $contentType, string $contentKey): MovieModel|Episode|null
    {
        $model = $contentType === 'movie' ? MovieModel::query() : Episode::query();

        return $model
            ->where('id', $contentKey)
            ->when(
                ctype_digit($contentKey),
                fn ($query) => $query->orWhere('num', (int) $contentKey)
            )
            ->first();
    }

    private function hasActiveRental($userId, Devices $device, MovieModel|Episode $content, string $contentType): bool
    {
        $contentIds = [(string) $content->id];

        if ($contentType === 'episode' && filled($content->season_id)) {
            $contentIds[] = (string) $content->season_id;
        }

        return PaymentHistory::where('user_id', $userId)
            ->where('status', 'success')
            ->where('expiry_date', '>', now())
            ->where('device_type', $device->device_type)
            ->where('app_payment_type', 'ppv')
            ->whereIn('movie_id', $contentIds)
            ->exists();
    }

    private function resolveMpdUrl(string $raw): array
    {
        // Case 1: Plain MPD URL
        if (Str::contains($raw, 'http') && Str::contains($raw, 'mpd')) {
            return [
                'url' => $raw,
                'source' => 'plaintext',
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
            'source' => 'decrypted',
        ];
    }
}
