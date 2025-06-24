<?php

namespace App\Http\Controllers;

use App\Models\EpisodeModel;
use DateTime;
use Firebase\JWT\JWT;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use SimpleXMLElement;
use Str;

class EpisodeController extends Controller
{
    private $validApiKey;
    protected $fCMNotificationController;

    public function __construct(FCMNotificationController $fCMNotificationController)
    {
        $this->validApiKey = config('app.api_key');
        $this->fCMNotificationController = $fCMNotificationController;
    }
    public function getBySeason(Request $request)
    {
        $apiKey = $request->header('X-Api-Key');

        if ($apiKey !== $this->validApiKey) {
            return response()->json(['status' => 'error', 'message' => 'Invalid API key'], 401);
        }

        $seasonId = $request->query('id');

        if (!$seasonId) {
            return response()->json([
                'status' => 'error',
                'message' => 'Missing season ID'
            ], 400);
        }

        // Get the is_enable query parameter, default is null
        $isEnableRequest = filter_var($request->query('is_enable', true), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $isEnableRequest = $isEnableRequest === null ? true : $isEnableRequest;

        $query = EpisodeModel::where('season_id', $seasonId);

        if ($isEnableRequest) {
            $query->where('status', 'Published')->where('isEnable', 1);
        }

        $episodes = $query->orderByRaw("CAST(SUBSTRING_INDEX(title, 'Episode ', -1) AS UNSIGNED)")->get();

        if ($episodes->isEmpty()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Episodes not found'
            ], 404);
        }

        return response()->json($episodes);
    }

    public function getById(Request $request, $id)
    {
        try {
            $apiKey = $request->header('X-Api-Key');

            if ($apiKey !== $this->validApiKey) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid API key'
                ], 401);
            }

            $episode = EpisodeModel::where('id', $id)->first();

            if (!$episode) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Episode not found'
                ], 404);
            }

            return response()->json([
                'status' => 'success',
                'episode' => $episode
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Something went wrong' . $e->getMessage(),
            ], 500);
        }
    }


    public function insert(Request $request)
    {
        $apiKey = $request->header('X-Api-Key');

        if ($apiKey !== $this->validApiKey) {
            return response()->json(['status' => 'error', 'message' => 'Invalid API key'], 401);
        }

        try {
            // Validate incoming request data
            $validated = $request->validate([
                'title' => 'required|string|max:255',
                'desc' => 'nullable|string',
                'txt' => 'nullable|string',
                'season_id' => 'required|string',
                'img' => 'nullable|string',
                'url' => 'nullable|string',
                'dash_url' => 'nullable|string',
                'hls_url' => 'nullable|string',
                'ppv_amount' => 'nullable|string',
                'status' => 'nullable|string|in:Published,Scheduled,Draft',
                'create_date' => 'nullable|string',
                'isProtected' => 'boolean',
                'isPPV' => 'boolean',
                'isPremium' => 'boolean',
                'isEnable' => 'boolean',
                'movie_id' => 'nullable|int',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        }

        try {
            // Format create_date to "June 5, 2025" format
            $validated['create_date'] = !empty($validated['create_date'])
                ? (new DateTime($validated['create_date']))->format('F j, Y')
                : now()->format('F j, Y');
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => 'Invalid date format.'], 400);
        }

        try {
            // Add required fields
            $validated['id'] = Str::random(10);
            $validated['views'] = 0;

            if (!empty($validated['dash_url']) && !empty($validated['isProtected'])) {
                $token = $this->generateFromMpd($validated['dash_url']);
                if ($token) {
                    $validated['token'] = $token;
                }
            }

            // Create the episode
            $episode = EpisodeModel::create($validated);

            // Eager load movie if available via relationship
            $episode->load('movie');

            // Only send notification if status is Published
            $shouldNotify = $request->boolean('notification', true); // Default false

            if ($shouldNotify && ($episode->status ?? '') === 'Published') {
                $movieTitle = $episode->movie->title ?? 'Unknown Movie';
                $movieImage = $episode->movie->cover_img ?? '';
                $movieKey = $episode->movie->id ?? '';

                // Prepare FCM notification
                $fakeRequest = new Request([
                    'title' => "{$movieTitle} {$episode->txt}",
                    'body' => 'New episode streaming on Zo Stream',
                    'image' => $movieImage,
                    'key' => $movieKey,
                ]);

                // Send the notification
                $this->fCMNotificationController->send($fakeRequest);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Episode inserted successfully',
                'episode' => $episode
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to insert episode: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $apiKey = $request->header('X-Api-Key');

        if ($apiKey !== $this->validApiKey) {
            return response()->json(['status' => 'error', 'message' => 'Invalid API key'], 401);
        }

        $episode = EpisodeModel::where('id', $id)->first();

        if (!$episode) {
            return response()->json(['status' => 'error', 'message' => 'Episode not found'], 404);
        }

        // Validate the incoming request
        $validated = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'desc' => 'nullable|string',
            'txt' => 'nullable|string',
            'season_id' => 'sometimes|required|string',
            'img' => 'nullable|string',
            'url' => 'nullable|string',
            'dash_url' => 'nullable|string',
            'hls_url' => 'nullable|string',
            'ppv_amount' => 'nullable|string',
            'isProtected' => 'boolean',
            'isPPV' => 'boolean',
            'isPremium' => 'boolean',
            'isEnable' => 'boolean',
            'status' => 'nullable|string|in:Published,Scheduled,Draft',
            'create_date' => 'nullable|string',
        ]);

        $validated['create_date'] = !empty($validated['create_date'])
            ? (new DateTime($validated['create_date']))->format('F j, Y')
            : now()->format('F j, Y');

        if (!empty($validated['dash_url']) && !empty($validated['isProtected'])) {
            $token = $this->generateFromMpd($validated['dash_url']);
            if ($token) {
                $validated['token'] = $token;
            }
        }

        $episode->update($validated);

        return response()->json([
            'status' => 'success',
            'message' => 'Episode updated successfully',
            'episode' => $episode
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $apiKey = $request->header('X-Api-Key');

        if ($apiKey !== $this->validApiKey) {
            return response()->json(['status' => 'error', 'message' => 'Invalid API key'], 401);
        }

        $episode = EpisodeModel::where('id', $id)->first();

        if (!$episode) {
            return response()->json(['status' => 'error', 'message' => 'Episode not found'], 404);
        }

        $episode->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Episode deleted successfully'
        ]);
    }

    private function generateFromMpd(string $encryptedMpd): ?string
    {
        if (!$encryptedMpd)
            return null;

        $shaKey = 'd4c6198dabafb243b0d043a3c33a9fe171f81605158c267c7dfe5f66df29559a';

        if (!$encryptedMpd) {
            return response()->json(['error' => 'Missing mpd parameter'], 400);
        }

        $decryptionKey = hash(
            'sha256',
            $shaKey,
            true
        );

        // === Step 1: Decrypt MPD URL ===
        $data = base64_decode($encryptedMpd);
        $iv = substr($data, 0, 16);
        $cipherText = substr($data, 16);

        $decryptedMessage = openssl_decrypt(
            $cipherText,
            'aes-256-cbc',
            $decryptionKey,
            OPENSSL_RAW_DATA,
            $iv
        );

        if (!$decryptedMessage) {
            return response()->json(['error' => 'Failed to decrypt MPD URL'], 500);
        }

        // === Step 2: Fetch and parse MPD XML ===
        $xmlString = @file_get_contents($decryptedMessage);
        if (!$xmlString) {
            return response()->json(['error' => 'Unable to load MPD'], 500);
        }

        $xml = new SimpleXMLElement($xmlString);
        $namespaces = $xml->getNamespaces(true);

        foreach ($xml->Period->AdaptationSet as $adaptationSet) {
            foreach ($adaptationSet->ContentProtection as $cp) {
                $attrs = $cp->attributes($namespaces['cenc'] ?? '');
                if (isset($attrs['default_KID'])) {
                    $uuid = str_replace(['-', '{', '}'], '', (string) $attrs['default_KID']);
                    $keyHex = bin2hex(hex2bin($uuid));

                    $communicationKeyAsBase64 = "uoy1wOPkyPQznp7MIb8auiSoaeSbRn2ExzQdFZrsuPQ=";
                    $communicationKeyId = "c52ad793-022a-447f-8250-b2ba00568b80";
                    $communicationKey = base64_decode($communicationKeyAsBase64);

                    $payload = [
                        "version" => 1,
                        "com_key_id" => $communicationKeyId,
                        "message" => [
                            "type" => "entitlement_message",
                            "version" => 2,
                            "content_keys_source" => [
                                "inline" => [
                                    ["id" => $keyHex]
                                ]
                            ]
                        ]
                    ];

                    return JWT::encode($payload, $communicationKey, 'HS256');
                }
            }
        }

        return null;
    }
}
