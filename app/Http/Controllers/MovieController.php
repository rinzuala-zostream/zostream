<?php

namespace App\Http\Controllers;

use App\Models\EpisodeModel;
use App\Models\MovieModel;
use Carbon\Carbon;
use DateTime;
use Firebase\JWT\JWT;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use SimpleXMLElement;
use Str;

class MovieController extends Controller
{
    private $validApiKey;
    private $fCMNotificationController;

    public function __construct(FCMNotificationController $fCMNotificationController)
    {
        $this->validApiKey = config('app.api_key');
        $this->fCMNotificationController = $fCMNotificationController;
    }

    public function getMovies(Request $request)
    {
        $apiKey = $request->header('X-Api-Key');

        if ($apiKey !== $this->validApiKey) {
            return response()->json(['status' => 'error', 'message' => 'Invalid API key']);
        }

        $request->validate([
            'id' => 'nullable|string',
            'range' => 'nullable|string',
            'category' => 'nullable|string',
            'category_type' => 'nullable|string',
            'age_restriction' => 'nullable|string|in:true,false',
            'platform' => 'nullable|string', // optional query fallback
        ]);

        // ✅ Read platform (header first, then query)
        $platform = strtolower($request->header('X-Platform') ?? $request->query('platform', ''));

        // ✅ Configure which categories to hide per platform (section names)
        $hiddenByPlatform = [
            'ios' => ['Hollywood', 'Bollywood', '18+'],
            'tvos' => ['18+'],
            'macos' => [],
            'android' => [],
            'web' => [],
            '_default' => ['18+'], // fallback for unknown platforms
        ];

        // ✅ Decide hidden categories (only if platform explicitly passed)
        $hiddenCategories = [];
        if ($platform !== '') {
            $hiddenCategories = $hiddenByPlatform[$platform] ?? $hiddenByPlatform['_default'];
        }

        // ✅ Movie-level skip checks (map section/display → movie attribute predicate)
        $skipChecks = [
            'Hollywood' => fn($m) => (int) ($m->isHollywood ?? 0) === 1,
            'Bollywood' => fn($m) => (int) ($m->isBollywood ?? 0) === 1,
            'Mizo' => fn($m) => (int) ($m->isMizo ?? 0) === 1,
            'Asian' => fn($m) => (int) ($m->isKorean ?? 0) === 1,
            'Series' => fn($m) => (int) ($m->isSeason ?? 0) === 1,
            'Documentary' => fn($m) => (int) ($m->isDocumentary ?? 0) === 1,
            'Pay Per View' => fn($m) => (int) ($m->isPayPerView ?? 0) === 1,
            '18+' => fn($m) => (int) ($m->isAgeRestricted ?? 0) === 1,
            'Free' => fn($m) => (int) ($m->isPremium ?? 0) === 0,
            'Animation' => fn($m) => stripos((string) ($m->genre ?? ''), 'animation') !== false,
            // "Most Watched", "Latest Update", "New Release" are list types → no direct movie flag
        ];

        $shouldSkip = function ($movie) use ($platform, $hiddenCategories, $skipChecks) {
            if ($platform === '')
                return false; // no platform → no skipping
            foreach ($hiddenCategories as $name) {
                if (isset($skipChecks[$name]) && $skipChecks[$name]($movie)) {
                    return true;
                }
            }
            return false;
        };

        $id = $request->query('id') ?? null;
        $range = $request->query('range') ?? null;
        $categoryKey = strtolower($request->query('category') ?? '');
        $categoryType = strtolower($request->query('category_type') ?? '');
        $ageRestriction = ($request->query('age_restriction') ?? 'false') === 'true' ? 1 : 0;

        $isEnableRequest = filter_var($request->query('is_enable', true), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $isEnableRequest = $isEnableRequest === null ? true : $isEnableRequest;

        if ($id) {

            $query = $isEnableRequest
                ? MovieModel::where('status', 'Published')->where('isEnable', 1)
                : MovieModel::query();

            $movie = $query->where('id', $id)->first();

            if (!$movie) {
                return response()->json(['status' => 'error', 'message' => 'Movie not found']);
            }

            // (Optional) If you want single-item view to respect skips too:
            // if ($shouldSkip($movie)) { return response()->json(['status'=>'success','data'=>[]]); }

            return response()->json($this->transformMovie($movie))
                ->header('Content-Type', 'application/json');

        } else if ($range || $categoryKey) {

            // Map display names to columns for category path
            $displayToColumn = [
                "hollywood" => "isHollywood",
                "bollywood" => "isBollywood",
                "mizo" => "isMizo",
                "animation" => "genre",
                "asian" => "isKorean",
                "most watched" => "mostwatch",
                "pay per view" => "isPayPerView",
                "new release" => "newrelease",
                "latest update" => "all",
                "series" => "isSeason",
                "documentary" => "isDocumentary",
                "18+" => "isAgeRestricted",
                "free" => "free",
            ];

            // If platform present, block fetching a hidden category by name directly
            if ($platform !== '') {
                foreach ($hiddenCategories as $hiddenName) {
                    if (strtolower($hiddenName) === $categoryKey) {
                        return response()->json(['status' => 'success', 'data' => []])
                            ->header('Content-Type', 'application/json');
                    }
                }
            }

            $rangeParts = explode('-', $range ?? '1-10');
            $start = max(((int) $rangeParts[0] - 1), 0);
            $count = max(((int) $rangeParts[1] - $start), 10);

            $categoryMapping = $displayToColumn;
            $column = $categoryType ? $categoryKey : ($categoryMapping[$categoryKey] ?? null);

            if (!$column) {
                return response()->json(['status' => 'error', 'message' => 'Invalid category']);
            }

            $query = MovieModel::query()
                ->where('isEnable', 1)
                ->where('status', 'Published');

            if ($column === 'newrelease') {
                $query->whereNotNull('release_on')
                    ->when(!$ageRestriction, fn($q) => $q->where('isAgeRestricted', $ageRestriction))
                    ->orderByRaw("STR_TO_DATE(release_on, '%d %b, %Y') DESC");
            } elseif ($column === 'mostwatch') {
                $query->when(!$ageRestriction, fn($q) => $q->where('isAgeRestricted', $ageRestriction))
                    ->orderByDesc('views');
            } elseif ($column === 'all') {
                $query->when(!$ageRestriction, fn($q) => $q->where('isAgeRestricted', $ageRestriction))
                    ->orderByDesc('num');
            } elseif ($column === 'free') {
                $query->where('isPremium', 0)
                    ->when(!$ageRestriction, fn($q) => $q->where('isAgeRestricted', $ageRestriction))
                    ->orderByDesc('num');
            } elseif (
                in_array($column, [
                    "isBollywood",
                    "isCompleted",
                    "isDocumentary",
                    "isDubbed",
                    "isEnable",
                    "isHollywood",
                    "isKorean",
                    "isMizo",
                    "isPayPerView",
                    "isPremium",
                    "isAgeRestricted",
                    "isSeason",
                    "isSubtitle"
                ])
            ) {
                $query->where($column, 1)
                    ->when(!$ageRestriction, fn($q) => $q->where('isAgeRestricted', $ageRestriction))
                    ->orderByDesc('num');
            } else {
                $query->where('genre', 'LIKE', "%$categoryKey%")
                    ->when(!$ageRestriction, fn($q) => $q->where('isAgeRestricted', $ageRestriction))
                    ->orderByDesc('num');
            }

            $movies = $query->offset($start)->limit($count)->get();

            // ✅ Per-movie skip (platform present)
            if ($platform !== '') {
                $movies = $movies->reject($shouldSkip)->values();
            }

            return response()->json(
                data: $movies->map(fn($m) => $this->transformMovie($m))
            )->header('Content-Type', 'application/json');

        } else {
            // Full sections response (default path)
            $categories = [
                "New Release" => ["where" => "release_on IS NOT NULL", "order" => "STR_TO_DATE(release_on, '%d %b, %Y') DESC"],
                "Most Watched" => ["where" => "1", "order" => "views DESC"],
                "Pay Per View" => ["where" => "isPayPerView = 1", "order" => "num DESC"],
                "Latest Update" => ["where" => "1", "order" => "num DESC"],
                "Asian" => ["where" => "isKorean = 1", "order" => "num DESC"],
                "Series" => ["where" => "isSeason = 1", "order" => "num DESC"],
                "Hollywood" => ["where" => "isHollywood = 1", "order" => "num DESC"],
                "Animation" => ["where" => "genre LIKE '%Animation%'", "order" => "num DESC"],
                "18+" => ["where" => "isAgeRestricted = 1", "order" => "num DESC"],
                "Bollywood" => ["where" => "isBollywood = 1", "order" => "num DESC"],
                "Mizo" => ["where" => "isMizo = 1", "order" => "num DESC"],
                "Documentary" => ["where" => "isDocumentary = 1", "order" => "num DESC"],
                "Free" => ["where" => "isPremium = 0", "order" => "num DESC"],
            ];

            // ✅ Remove whole sections if platform was provided (keeps UI headings clean)
            if ($platform !== '') {
                foreach ($hiddenCategories as $hiddenName) {
                    unset($categories[$hiddenName]);
                }
            }

            $data = [];
            $fetchSize = ($platform !== '') ? 50 : 10; // over-fetch when skipping to keep ~10 items

            foreach ($categories as $name => $clause) {
                if ($name === "18+" && !$ageRestriction)
                    continue;

                $where = $clause['where'];
                $order = $clause['order'];

                $list = MovieModel::whereRaw("isEnable = 1 AND $where")
                    ->where('status', 'Published')
                    ->when(!$ageRestriction && strpos($where, 'isAgeRestricted') === false, function ($q) use ($ageRestriction) {
                        return $q->where('isAgeRestricted', $ageRestriction);
                    })
                    ->orderByRaw($order)
                    ->limit($fetchSize)
                    ->get();

                // ✅ Per-movie skip inside each section
                if ($platform !== '') {
                    $list = $list->reject($shouldSkip)->values();
                }

                // keep at most 10 items per section
                $list = $list->take(10);

                if (!$list->isEmpty()) {
                    $data[$name] = $list->map(fn($m) => $this->transformMovie($m));
                }
            }

            return response()->json($data)->header('Content-Type', 'application/json');
        }
    }

    private function transformMovie($movie)
    {
        foreach (['isProtected', 'isBollywood', 'isCompleted', 'isDocumentary', 'isDubbed', 'isEnable', 'isHollywood', 'isKorean', 'isMizo', 'isPayPerView', 'isPremium', 'isAgeRestricted', 'isSeason', 'isSubtitle'] as $key) {
            $movie->$key = (bool) $movie->$key;
        }

        $movie->num = (int) $movie->num;
        $movie->views = (int) $movie->views;

        return $movie;
    }

    public function incrementView(Request $request)
    {
        $apiKey = $request->header('X-Api-Key');

        if ($apiKey !== $this->validApiKey) {
            return response()->json(['status' => 'error', 'message' => 'Invalid API key']);
        }

        $request->validate([
            'movie_id' => 'required|string',
            'movie_type' => 'required|string|in:movie,episode',
        ]);

        $id = $request->input('movie_id');
        $type = strtolower($request->input('movie_type'));

        $model = $type === 'movie' ? MovieModel::class : EpisodeModel::class;

        $item = $model::where('id', $id)->first();

        if (!$item) {
            return response()->json(['status' => 'error', 'message' => 'Content not found']);
        }

        $item->increment('views', 1);

        return response()->json(['status' => 'success', 'message' => 'View count incremented']);
    }

    public function insert(Request $request)
    {
        $apiKey = $request->header('X-Api-Key');

        if ($apiKey !== $this->validApiKey) {
            return response()->json(['status' => 'error', 'message' => 'Invalid API key']);
        }

        try {
            $validated = $request->validate([
                'title' => 'required|string|max:255',
                'description' => 'nullable|string',
                'genre' => 'nullable|string',
                'director' => 'nullable|string',
                'duration' => 'nullable|string',
                'release_on' => 'nullable|string',
                'title_img' => 'nullable|string',
                'cover_img' => 'nullable|string',
                'poster' => 'nullable|string',
                'url' => 'nullable|string',
                'dash_url' => 'nullable|string',
                'hls_url' => 'nullable|string',
                'trailer' => 'nullable|string',
                'subtitle' => 'nullable|string',
                'token' => 'nullable|string',
                'views' => 'nullable|int',
                'status' => 'nullable|string|in:Published,Draft,Scheduled',
                'create_date' => 'nullable|string',
                'ppv_amount' => 'nullable|string',

                // Boolean flags
                'isProtected' => 'boolean',
                'isBollywood' => 'boolean',
                'isCompleted' => 'boolean',
                'isDocumentary' => 'boolean',
                'isAgeRestricted' => 'boolean',
                'isDubbed' => 'boolean',
                'isEnable' => 'boolean',
                'isHollywood' => 'boolean',
                'isKorean' => 'boolean',
                'isMizo' => 'boolean',
                'isPayPerView' => 'boolean',
                'isPremium' => 'boolean',
                'isSeason' => 'boolean',
                'isSubtitle' => 'boolean',
            ]);

            $validated['id'] = Str::random(10);

            $validated['create_date'] = !empty($validated['create_date'])
                ? (new DateTime($validated['create_date']))->format('F j, Y')
                : now()->format('F j, Y');

            if (!empty($validated['release_on'])) {
                $validated['release_on'] = Carbon::parse($validated['release_on'])->format('F j, Y');
            }

            if (!empty($validated['dash_url']) && !empty($validated['isProtected'])) {
                $token = $this->generateFromMpd($validated['dash_url']);
                if ($token) {
                    $validated['token'] = $token;
                }
            }

            // ✅ Ensure trailer is stored as empty string if null
            $validated['trailer'] = $validated['trailer'] ?? '';

            // ✅ Always create the movie
            $movie = MovieModel::create($validated);

            // Notification
            $shouldNotify = $request->boolean('notification', true);
            if ($shouldNotify && $movie->status === 'Published') {
                $fakeRequest = new Request([
                    'title' => $movie->title,
                    'body' => 'Streaming on Zo Stream',
                    'image' => $movie->cover_img ?? '',
                    'key' => $movie->id ?? '',
                ]);
                $this->fCMNotificationController->send($fakeRequest);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Movie inserted successfully',
                'movie' => $movie
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Insert failed.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function update(Request $request, $id): JsonResponse
    {
        $apiKey = $request->header('X-Api-Key');

        if ($apiKey !== $this->validApiKey) {
            return response()->json(['status' => 'error', 'message' => 'Invalid API key']);
        }

        $movie = MovieModel::where('id', $id)->first();

        if (!$movie) {
            return response()->json(['status' => 'error', 'message' => 'Movie not found'], 404);
        }

        try {
            $validated = $request->validate([
                'title' => 'nullable|string|max:255',
                'description' => 'nullable|string',
                'genre' => 'nullable|string',
                'director' => 'nullable|string',
                'duration' => 'nullable|string',
                'release_on' => 'nullable|string',
                'cover_img' => 'nullable|string',
                'title_img' => 'nullable|string',
                'poster' => 'nullable|string',
                'url' => 'nullable|string',
                'dash_url' => 'nullable|string',
                'hls_url' => 'nullable|string',
                'trailer' => 'nullable|string',
                'subtitle' => 'nullable|string',
                'token' => 'nullable|string',
                'views' => 'nullable|int',
                'status' => 'nullable|string|in:Published,Draft,Scheduled',
                'create_date' => 'nullable|string',
                'ppv_amount' => 'nullable|string',

                // Boolean flags
                'isProtected' => 'boolean',
                'isBollywood' => 'boolean',
                'isCompleted' => 'boolean',
                'isDocumentary' => 'boolean',
                'isAgeRestricted' => 'boolean',
                'isDubbed' => 'boolean',
                'isEnable' => 'boolean',
                'isHollywood' => 'boolean',
                'isKorean' => 'boolean',
                'isMizo' => 'boolean',
                'isPayPerView' => 'boolean',
                'isPremium' => 'boolean',
                'isSeason' => 'boolean',
                'isSubtitle' => 'boolean',
            ]);

            if (isset($validated['release_on'])) {
                $validated['release_on'] = Carbon::parse($validated['release_on'])->format('F j, Y');
            }

            if (!empty($validated['dash_url']) && !empty($validated['isProtected'])) {
                $token = $this->generateFromMpd($validated['dash_url']);
                if ($token) {
                    $validated['token'] = $token;
                }
            }

            // ✅ Ensure trailer is stored as empty string if null
            if (!isset($validated['trailer']) || $validated['trailer'] === null) {
                $validated['trailer'] = '';
            }

            $movie->update($validated);

            return response()->json([
                'status' => 'success',
                'message' => 'Movie updated successfully',
                'movie' => $movie
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Update failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy(Request $request, $id)
    {
        $apiKey = $request->header('X-Api-Key');

        if ($apiKey !== $this->validApiKey) {
            return response()->json(['status' => 'error', 'message' => 'Invalid API key']);
        }

        $movie = MovieModel::where('id', $id)->first();

        if (!$movie) {
            return response()->json(['status' => 'error', 'message' => 'Movie not found'], 404);
        }

        try {
            $movie->delete();

            return response()->json([
                'status' => 'success',
                'message' => 'Movie deleted successfully',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Delete failed',
                'error' => $e->getMessage(),
            ], 500);
        }
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
        $fixedUrl = str_replace(' ', '%20', $decryptedMessage);
        $xmlString = @file_get_contents($fixedUrl);

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
