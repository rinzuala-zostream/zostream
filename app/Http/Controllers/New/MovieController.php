<?php

namespace App\Http\Controllers\New;

use App\Http\Controllers\Controller;
use App\Models\New\Episode;
use App\Models\New\PlanFeature;
use App\Models\New\Subscription;
use App\Models\New\VideoUrl;
use Illuminate\Http\Request;
use App\Models\MovieModel;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Exception;

class MovieController extends Controller
{
    /**
     * 📋 List all movies with pagination
     */
    public function index(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 15);
            $movies = MovieModel::orderBy('create_date', 'desc')->paginate($perPage);

            return response()->json([
                'status' => 'success',
                'data' => $movies
            ]);
        } catch (Exception $e) {
            Log::error('Movie index error', ['error' => $e->getMessage()]);
            return $this->errorResponse('Failed to fetch movies', $e);
        }
    }

    /**
     * 🎞️ Get movie by ID (without URLs)
     */
    public function getById(Request $request, $id)
    {
        try {
            $type = strtolower($request->query('type'));

            if ($type === 'episode') {
                $movie = Episode::with('season')
                    ->where('id', $id)
                    ->first();

                if (!$movie) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Episode not found'
                    ], 404);
                }

                $data = $movie->toArray();
                $data['ppv_amount'] = $data['amount'] ?? 0;
                unset($data['amount']);

            } else {
                $movie = MovieModel::where('id', $id)->first();

                if (!$movie) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Movie not found'
                    ], 404);
                }

                $data = $movie->toArray();
            }

            return response()->json($data);
        } catch (Exception $e) {
            Log::error('Movie getById error', [
                'id' => $id,
                'error' => $e->getMessage()
            ]);

            return $this->errorResponse('Failed to fetch movie details', $e);
        }
    }

    /**
     * 🔗 Get only movie links (URLs)
     */
    public function getLink(Request $request, $id)
    {
        try {
            $type = strtolower($request->query('type', 'movie'));

            if (!in_array($type, ['movie', 'episode'], true)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid type. Allowed: movie, episode'
                ], 422);
            }

            if ($type === 'episode') {
                $episode = Episode::with('season')
                    ->where('id', $id)
                    ->first();

                if (!$episode) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Episode not found'
                    ], 404);
                }

                $urls = VideoUrl::where('episode_id', $id)->get();

                if ($urls->isEmpty()) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'No video URLs found for this episode'
                    ], 404);
                }

                $links = $urls
                    ->filter(fn($item) => !empty($item->url))
                    ->map(function ($item) {
                        return [
                            'id' => $item->id,
                            'quality' => $item->quality,
                            'type' => $item->type,
                            'url' => $item->url,
                        ];
                    })
                    ->values();

                return response()->json([
                    'status' => 'success',
                    'type' => 'episode',
                    'movie_id' => $episode->season?->movie_id,
                    'episode_id' => $episode->id,
                    'title' => $episode->title,
                    'links' => $links
                ]);
            }

            $movie = MovieModel::select(['num', 'title', 'url', 'dash_url', 'hls_url', 'trailer'])
                ->where('id', $id)
                ->first();

            if (!$movie) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Movie not found'
                ], 404);
            }

            $links = array_filter([
                'url' => $movie->url,
            ], fn($value) => $value !== null && $value !== '');

            if (empty($links)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No video links found for this movie'
                ], 404);
            }

            return response()->json([
                'status' => 'success',
                'type' => 'movie',
                'movie_id' => $movie->num,
                'title' => $movie->title,
                'links' => $links
            ]);
        } catch (\Exception $e) {
            \Log::error('Movie getLink error', [
                'id' => $id,
                'type' => $request->query('type', 'movie'),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch movie links',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get one PPV payable content item.
     *
     * type=movie:
     *   movie_id = movie.id
     *
     * type=episode:
     *   movie_id = episode.id
     *   Response includes current season so clients can rent either episode or season.
     *
     * subscriptionId is optional and applies the plan feature ppv_discount.
     */
    public function getPayPerViewContent(Request $request)
    {
        try {
            $request->merge([
                'type' => strtolower((string) $request->query('type')),
            ]);

            $validated = $request->validate([
                'type' => 'required|string|in:movie,episode',
                'movie_id' => 'required|string',
                'subscriptionId' => 'nullable|string',
            ]);

            $type = strtolower($validated['type']);
            $discountPercent = $this->getPpvDiscountPercent($validated['subscriptionId'] ?? null);

            if ($type === 'movie') {
                return $this->getPayPerViewMovieContent($validated['movie_id'], $discountPercent);
            }

            return $this->getPayPerViewEpisodeContent($validated['movie_id'], $discountPercent);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (Exception $e) {
            Log::error('Movie getPayPerViewContent error', [
                'query' => $request->query(),
                'error' => $e->getMessage()
            ]);

            return $this->errorResponse('Failed to fetch pay per view content', $e);
        }
    }

    private function getPayPerViewMovieContent(string $movieId, float $discountPercent)
    {
        $movie = MovieModel::where('id', $movieId)->first();

        if (!$movie) {
            return response()->json([
                'status' => 'error',
                'message' => 'Movie not found'
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'type' => 'movie',
                'payment_movie_id' => $movie->id,
                'payment_options' => [
                    'movie' => $this->makePpvPaymentOption($movie->id, $movie->ppv_amount ?? 0, $discountPercent),
                ],
                'movie_id' => $movie->id,
                'movie_num' => $movie->num,
                'title' => $movie->title,
                'poster' => $movie->poster,
                'isPayPerView' => (bool) $movie->isPayPerView,
                'ppv_amount' => $movie->ppv_amount ?? 0,
                'discount_percent' => $discountPercent,
                'discount_amount' => $this->calculatePpvDiscountAmount($movie->ppv_amount ?? 0, $discountPercent),
                'final_ppv_price' => $this->calculateFinalPpvPrice($movie->ppv_amount ?? 0, $discountPercent),
            ]
        ]);
    }

    private function getPayPerViewEpisodeContent(string $episodeId, float $discountPercent)
    {
        $episode = Episode::with([
            'season.movie',
            'season.episodes' => fn($query) => $query->orderBy('episode_number')
        ])->where('id', $episodeId)->first();

        if (!$episode) {
            return response()->json([
                'status' => 'error',
                'message' => 'Episode not found'
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'type' => 'episode',
                'payment_movie_id' => $episode->id,
                'payment_options' => [
                    'episode' => $this->makePpvPaymentOption($episode->id, $episode->amount ?? 0, $discountPercent),
                    'season' => $episode->season
                        ? $this->makeSeasonPpvPaymentOption($episode->season, $discountPercent)
                        : null,
                ],
                'movie_id' => $episode->season?->movie_id,
                'episode_id' => $episode->id,
                'title' => $episode->title,
                'poster' => $episode->thumbnail,
                'isPayPerView' => (bool) $episode->isPayPerView,
                'ppv_amount' => $episode->amount ?? 0,
                'discount_percent' => $discountPercent,
                'discount_amount' => $this->calculatePpvDiscountAmount($episode->amount ?? 0, $discountPercent),
                'final_ppv_price' => $this->calculateFinalPpvPrice($episode->amount ?? 0, $discountPercent),
                'season' => $episode->season ? [
                    'id' => $episode->season->id,
                    'movie_id' => $episode->season->movie_id,
                    'title' => $episode->season->title,
                    'poster' => $episode->season->poster,
                    'isPayPerView' => (bool) $episode->season->isPayPerView,
                    'ppv_amount' => $episode->season->amount ?? 0,
                ] : null,
            ]
        ]);
    }

    private function makeSeasonPpvPaymentOption($season, float $discountPercent): array
    {
        $ppvEpisodes = $season->episodes
            ->filter(fn($episode) => (bool) $episode->isPayPerView);

        $episodeTotalAmount = (float) $ppvEpisodes->sum(fn($episode) => (float) ($episode->amount ?? 0));
        $seasonAmount = (float) ($season->amount ?? 0);

        $option = $this->makePpvPaymentOption($season->id, $seasonAmount, $discountPercent);
        $episodesFinalPrice = $this->calculateFinalPpvPrice($episodeTotalAmount, $discountPercent);

        return array_merge($option, [
            'ppv_episode_count' => $ppvEpisodes->count(),
            'ppv_episodes_total_amount' => round($episodeTotalAmount, 2),
            'ppv_episodes_discount_amount' => $this->calculatePpvDiscountAmount($episodeTotalAmount, $discountPercent),
            'ppv_episodes_final_price' => $episodesFinalPrice,
            'season_benefit_amount' => round(max($episodesFinalPrice - $option['final_ppv_price'], 0), 2),
            'season_benefit_message' => $this->makeSeasonBenefitMessage($episodesFinalPrice, $option['final_ppv_price']),
        ]);
    }

    private function getPpvDiscountPercent(?string $subscriptionId): float
    {
        if (!$subscriptionId) {
            return 0;
        }

        $subscription = Subscription::with('plan')->find($subscriptionId);

        if (!$subscription || !$subscription->isActive() || !$subscription->plan) {
            return 0;
        }

        $featuresQuery = PlanFeature::query()->where('is_active', 1);

        if (Schema::hasColumn('n_plan_features', 'plan_id')) {
            $featuresQuery->where('plan_id', $subscription->plan_id);
        } else {
            $featuresQuery->where('plan_name', $subscription->plan->name);
        }

        return (float) $featuresQuery
            ->max('ppv_discount');
    }

    private function makePpvPaymentOption(string $paymentMovieId, $amount, float $discountPercent): array
    {
        $amount = (float) $amount;

        return [
            'payment_movie_id' => $paymentMovieId,
            'ppv_amount' => round($amount, 2),
            'discount_percent' => round($discountPercent, 2),
            'discount_amount' => $this->calculatePpvDiscountAmount($amount, $discountPercent),
            'final_ppv_price' => $this->calculateFinalPpvPrice($amount, $discountPercent),
        ];
    }

    private function calculatePpvDiscountAmount($amount, float $discountPercent): float
    {
        return round(((float) $amount * $discountPercent) / 100, 2);
    }

    private function calculateFinalPpvPrice($amount, float $discountPercent): float
    {
        return round(max((float) $amount - $this->calculatePpvDiscountAmount($amount, $discountPercent), 0), 2);
    }

    private function makeSeasonBenefitMessage(float $episodesFinalPrice, float $seasonFinalPrice): string
    {
        $benefitAmount = round(max($episodesFinalPrice - $seasonFinalPrice, 0), 2);

        if ($benefitAmount <= 0) {
            return 'Rent the full season to unlock all PPV episodes in this season.';
        }

        return "Save {$benefitAmount} by renting the full season instead of renting PPV episodes one by one.";
    }

    /**
     * 🔓 Admin-only link fetch with instant backend decryption.
     */
    public function adminGetLink(Request $request, $id)
    {
        try {
            $type = strtolower($request->query('type', 'movie'));

            if (!in_array($type, ['movie', 'episode'], true)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid type. Allowed: movie, episode'
                ], 422);
            }

            if ($type === 'episode') {
                $episode = Episode::with('season')
                    ->where('id', $id)
                    ->first();

                if (!$episode) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Episode not found'
                    ], 404);
                }

                $urls = VideoUrl::where('episode_id', $id)->get();

                if ($urls->isEmpty()) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'No video URLs found for this episode'
                    ], 404);
                }

                $links = $urls
                    ->filter(fn($item) => !empty($item->url))
                    ->map(function ($item) {
                        return [
                            'id' => $item->id,
                            'quality' => $item->quality,
                            'type' => $item->type,
                            'url' => $this->decryptAdminLink($item->url),
                        ];
                    })
                    ->values();

                return response()->json([
                    'status' => 'success',
                    'type' => 'episode',
                    'movie_id' => $episode->season?->movie_id,
                    'episode_id' => $episode->id,
                    'title' => $episode->title,
                    'links' => $links
                ]);
            }

            $movie = MovieModel::select([
                'num',
                'title',
                'url',
                'dash_url',
                'hls_url',
                'trailer',
                'subtitle',
            ])
                ->where('id', $id)
                ->first();

            if (!$movie) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Movie not found'
                ], 404);
            }

            $links = collect([
                'url' => $movie->url,
                'dash_url' => $movie->dash_url,
                'hls_url' => $movie->hls_url,
                'trailer' => $movie->trailer,
                'subtitle' => $movie->subtitle,
            ])
                ->filter(fn($value) => $value !== null && $value !== '')
                ->map(fn($value) => $this->decryptAdminLink($value))
                ->all();

            if (empty($links)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No video links found for this movie'
                ], 404);
            }

            return response()->json([
                'status' => 'success',
                'type' => 'movie',
                'movie_id' => $movie->num,
                'title' => $movie->title,
                'links' => $links
            ]);
        } catch (\Exception $e) {
            \Log::error('Movie adminGetLink error', [
                'id' => $id,
                'type' => $request->query('type', 'movie'),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch admin movie links',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    private function decryptAdminLink(?string $raw): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return $raw;
        }

        $rawParam = str_replace('%2B', '+', $raw);
        $rawParam = str_replace(' ', '+', $rawParam);
        $b64 = strtr($rawParam, '-_', '+/');
        $pad = strlen($b64) % 4;

        if ($pad) {
            $b64 .= str_repeat('=', 4 - $pad);
        }

        $data = @base64_decode($b64, true);

        if ($data === false || strlen($data) < 17) {
            return $raw;
        }

        $iv = substr($data, 0, 16);
        $cipherText = substr($data, 16);

        if (strlen($iv) !== 16 || $cipherText === '') {
            return $raw;
        }

        $shaKey = 'd4c6198dabafb243b0d043a3c33a9fe171f81605158c267c7dfe5f66df29559a';
        $decryptionKey = hash('sha256', $shaKey, true);
        $decryptedMessage = openssl_decrypt(
            $cipherText,
            'aes-256-cbc',
            $decryptionKey,
            OPENSSL_RAW_DATA,
            $iv
        );

        if ($decryptedMessage === false) {
            return $raw;
        }

        return trim(str_replace(["\r", "\n"], '', $decryptedMessage));
    }

    public function getMovies(Request $request)
    {

        $request->validate([
            'id' => 'nullable|string',
            'range' => 'nullable|string',
            'category' => 'nullable|string',
            'category_type' => 'nullable|string',
            'age_restriction' => 'nullable|string|in:true,false',
            'platform' => 'nullable|string',
        ]);

        // ✅ Header/Query mode detection
        $modeHeader = strtolower($request->header('X-Mode', ''));
        $isKidsByHeader = $modeHeader === 'kids';
        $isKidsByQuery = ($request->query('isChildMode') ?? 'false') === 'true';
        $isKidsMode = $isKidsByHeader || $isKidsByQuery;

        // ✅ Platform removed (always default)
        $platform = '';

        // ✅ Read user ID
        $userId = $request->header('X-User-Id') ?? $request->query('user_id', '');

        // ✅ Treat empty user ID same as Mizo-only user
        //$onlyMizoUser = $userId === 'AW7ovVnTdgWuvE1Uke7QTQ5OEQt1';
        $onlyMizoUser = empty($userId) || $userId === 'AW7ovVnTdgWuvE1Uke7QTQ5OEQt1';

        // ✅ Categories to hide per platform
        $hiddenByPlatform = [
            'ios' => ['Hollywood', 'Bollywood', '18+', 'Asian', 'Series', 'Documentary', 'Animation'],
            'tvos' => ['18+'],
            'macos' => [],
            'android' => [],
            'web' => [],
            '_default' => ['18+'],
        ];

        // ✅ Determine hidden categories
        $hiddenCategories = [];
        if (!$onlyMizoUser) {
            if ($isKidsMode) {
                $hiddenCategories = $hiddenByPlatform['_default'];
            }
        }

        // ✅ Skip checks for hidden categories
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
        ];

        $shouldSkip = function ($movie) use ($isKidsMode, $hiddenCategories, $skipChecks, $onlyMizoUser) {
            // ✅ Restrict non-Mizo content for Mizo-only user or empty user ID
            if ($onlyMizoUser && (int) ($movie->isMizo ?? 0) !== 1) {
                return true;
            }

            // ✅ Kids mode restriction
            if ($isKidsMode && (int) ($movie->isChildMode ?? 0) !== 1) {
                return true;
            }

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
        if ($isKidsMode) {
            $ageRestriction = 0;
        }

        $isEnableRequest = filter_var($request->query('is_enable', true), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $isEnableRequest = $isEnableRequest === null ? true : $isEnableRequest;

        $applyKidsFilter = function (\Illuminate\Database\Eloquent\Builder $q) use ($isKidsMode) {
            if ($isKidsMode) {
                $q->where('isChildMode', 1);
            }
            return $q;
        };

        // ✅ Single Movie by ID
        if ($id) {
            $query = $isEnableRequest
                ? MovieModel::where('status', 'Published')->where('isEnable', 1)
                : MovieModel::query();

            $query = $applyKidsFilter($query);
            if ($onlyMizoUser) {
                $query->where('isMizo', 1);
            }

            $movie = $query->where('id', $id)->first();
            if (!$movie) {
                return response()->json(['status' => 'error', 'message' => 'Movie not found']);
            }

            return response()->json($this->transformMovie($movie))
                ->header('Content-Type', 'application/json');
        }

        // ✅ Category/Range Fetch
        elseif ($range || $categoryKey) {
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

            $rangeParts = explode('-', $range ?? '1-10');
            $start = max(((int) $rangeParts[0] - 1), 0);
            $count = max(((int) $rangeParts[1] - $start), 10);

            $column = $categoryType ? $categoryKey : ($displayToColumn[$categoryKey] ?? null);
            if (!$column) {
                return response()->json(['status' => 'error', 'message' => 'Invalid category']);
            }

            $query = MovieModel::query()
                ->where('isEnable', 1)
                ->where('status', 'Published');

            $query = $applyKidsFilter($query);
            if ($onlyMizoUser) {
                $query->where('isMizo', 1);
            }

            if ($column === 'newrelease') {
                $query->whereNotNull('release_on')
                    ->when(!$ageRestriction, fn($q) => $q->where('isAgeRestricted', $ageRestriction))
                    ->orderByRaw("STR_TO_DATE(release_on, '%M %d, %Y') DESC");
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

            if ($isKidsMode && !$onlyMizoUser) {
                $movies = $movies->reject($shouldSkip)->values();
            }

            return response()->json(
                data: $movies->map(fn($m) => $this->transformMovie($m))
            )->header('Content-Type', 'application/json');
        }

        // ✅ Full sections response
        else {
            $categories = [
                "New Release" => ["where" => "release_on IS NOT NULL", "order" => "STR_TO_DATE(release_on, '%M %d, %Y') DESC"],
                "Most Watched" => ["where" => "1", "order" => "views DESC"],
                "Pay Per View" => ["where" => "isPayPerView = 1", "order" => "num DESC"],
                "Latest Update" => ["where" => "1", "order" => "STR_TO_DATE(create_date, '%M %d, %Y') DESC"],
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

            if (!$onlyMizoUser && $isKidsMode) {
                foreach ($hiddenCategories as $hiddenName) {
                    unset($categories[$hiddenName]);
                }
            }

            if ($isKidsMode) {
                unset($categories["18+"]);
            }

            $data = [];
            $fetchSize = $isKidsMode ? 50 : 10;

            foreach ($categories as $name => $clause) {
                if ($name === "18+" && !$ageRestriction)
                    continue;

                $where = $clause['where'];
                $order = $clause['order'];

                $builder = MovieModel::whereRaw("isEnable = 1 AND $where")
                    ->where('status', 'Published');

                if ($isKidsMode) {
                    $builder->where('isChildMode', 1);
                }
                if ($onlyMizoUser) {
                    $builder->where('isMizo', 1);
                }

                $builder = $builder->when(
                    !$ageRestriction && strpos($where, 'isAgeRestricted') === false,
                    fn($q) => $q->where('isAgeRestricted', $ageRestriction)
                )
                    ->orderByRaw($order)
                    ->limit($fetchSize);

                $list = $builder->get();

                if ($isKidsMode && !$onlyMizoUser) {
                    $list = $list->reject($shouldSkip)->values();
                }

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
        foreach ([
            'isProtected',
            'isBollywood',
            'isCompleted',
            'isDocumentary',
            'isDubbed',
            'isEnable',
            'isHollywood',
            'isKorean',
            'isMizo',
            'isPayPerView',
            'isPremium',
            'isAgeRestricted',
            'isSeason',
            'isSubtitle'
        ] as $key) {
            $movie->$key = (bool) $movie->$key;
        }

        $movie->num = (int) $movie->num;
        $movie->views = (int) $movie->views;

        // remove streaming URLs
        unset(
            $movie->url,
            $movie->dash_url,
            $movie->hls_url,
            $movie->token
        );

        return $movie;
    }

    /**
     * 🧩 Common JSON error handler
     */
    private function errorResponse(string $message, Exception $e, int $code = 500)
    {
        return response()->json([
            'status' => 'error',
            'message' => $message,
            'error' => $e->getMessage(),
        ], $code);
    }

    public function getUrls($episodeId)
    {
        try {

            $urls = VideoUrl::where('episode_id', $episodeId)->get();

            return response()->json([
                'status' => 'success',
                'data' => $urls
            ]);

        } catch (\Exception $e) {

            \Log::error('Get video urls error: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch video urls'
            ], 500);
        }
    }
}
