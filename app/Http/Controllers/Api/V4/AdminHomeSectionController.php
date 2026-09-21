<?php

namespace App\Http\Controllers\Api\V4;

use App\Http\Controllers\Controller;
use App\Services\HomeSectionLayoutService;
use App\Support\Api\V4Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminHomeSectionController extends Controller
{
    public function __construct(
        private readonly HomeSectionLayoutService $layout,
    ) {}

    public function index(): JsonResponse
    {
        return V4Response::success([
            'sections' => $this->layout->all(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'sections' => ['present', 'array', 'max:'.count(HomeSectionLayoutService::DEFAULTS)],
            'sections.*.key' => [
                'required',
                'string',
                'distinct',
                Rule::in(HomeSectionLayoutService::keys()),
            ],
            'sections.*.title' => ['required', 'string', 'max:120'],
        ]);

        return V4Response::success([
            'sections' => $this->layout->replace($validated['sections']),
        ], 'Home sections updated.');
    }
}
