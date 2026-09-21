<?php

namespace App\Http\Controllers\Api\V4;

use App\Http\Controllers\Controller;
use App\Services\HomeRecommendationService;
use App\Services\HomeSectionLayoutService;
use App\Support\Api\V4Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Throwable;

class HomeRecommendationController extends Controller
{
    public function __construct(
        private readonly HomeRecommendationService $recommendations,
        private readonly HomeSectionLayoutService $layout,
    ) {}

    public function home(Request $request): JsonResponse
    {
        $rawAgeRestriction = $request->query('age_restriction');
        if ($rawAgeRestriction !== null) {
            $normalizedAgeRestriction = filter_var(
                $rawAgeRestriction,
                FILTER_VALIDATE_BOOLEAN,
                FILTER_NULL_ON_FAILURE
            );
            if ($normalizedAgeRestriction !== null) {
                $request->merge(['age_restriction' => $normalizedAgeRestriction]);
            }
        }

        $request->merge([
            'content_mode' => strtolower(trim((string) $request->header('X-Mode', 'adult'))),
        ]);

        $validated = $request->validate([
            'section' => ['nullable', 'string', Rule::in(HomeSectionLayoutService::keys())],
            'page' => ['nullable', 'integer', 'min:1', 'max:25'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'content_mode' => ['required', 'string', Rule::in(['adult', 'kids'])],
            'age_restriction' => ['nullable', 'boolean'],
        ]);

        $userId = trim((string) $request->input('auth_user_id', ''));
        if ($userId === '') {
            return V4Response::error(
                'UNAUTHENTICATED',
                'An authenticated user is required.',
                401
            );
        }

        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? 10);
        $fetchLimit = min(($page * $perPage) + 1, 1251);
        $contentMode = $validated['content_mode'];
        $includeAgeRestricted = $request->boolean('age_restriction');
        $sectionLayout = isset($validated['section'])
            ? [$this->layout->definition($validated['section'])]
            : $this->layout->enabled();
        $requestedSections = array_column($sectionLayout, 'key');

        try {
            $homepage = $this->recommendations->homepage(
                $userId,
                $fetchLimit,
                $contentMode,
                $includeAgeRestricted,
                $requestedSections
            );
        } catch (Throwable $exception) {
            Log::warning('Home recommendation service unavailable', [
                'user_hash' => hash('sha256', $userId),
                'exception' => $exception,
            ]);

            return V4Response::error(
                'RECOMMENDER_UNAVAILABLE',
                'Personalized recommendations are temporarily unavailable.',
                503
            );
        }

        $sections = [];

        foreach ($requestedSections as $section) {
            $rawSection = $homepage[$section] ?? [];
            $anchor = null;
            if (is_array($rawSection) && array_key_exists('items', $rawSection)) {
                $anchor = $rawSection['anchor'] ?? null;
                $items = is_array($rawSection['items']) ? $rawSection['items'] : [];
            } else {
                $items = is_array($rawSection) ? $rawSection : [];
            }

            $sections[$section] = $this->paginate($items, $page, $perPage);
            if ($anchor !== null) {
                $sections[$section]['anchor'] = $anchor;
            }
        }

        return V4Response::success([
            'user' => $userId,
            'history_size' => (int) ($homepage['history_size'] ?? 0),
            'content_mode' => $contentMode,
            'age_restriction' => $includeAgeRestricted,
            'section_order' => array_map(fn (array $section): array => [
                'key' => $section['key'],
                'title' => $section['title'],
                'position' => $section['position'],
            ], $sectionLayout),
            'sections' => $sections,
        ]);
    }

    private function paginate(array $items, int $page, int $perPage): array
    {
        $offset = ($page - 1) * $perPage;
        $hasMore = count($items) > $offset + $perPage;
        $pageItems = array_values(array_slice($items, $offset, $perPage));

        return [
            'items' => $pageItems,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'returned' => count($pageItems),
                'has_more' => $hasMore,
                'next_page' => $hasMore ? $page + 1 : null,
                'previous_page' => $page > 1 ? $page - 1 : null,
            ],
        ];
    }
}
