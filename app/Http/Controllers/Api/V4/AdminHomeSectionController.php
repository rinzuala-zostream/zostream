<?php

namespace App\Http\Controllers\Api\V4;

use App\Http\Controllers\Controller;
use App\Services\HomeSectionLayoutService;
use App\Support\Api\V4Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminHomeSectionController extends Controller
{
    public function __construct(
        private readonly HomeSectionLayoutService $layout,
    ) {}

    public function index(): JsonResponse
    {
        return V4Response::success([
            'sections' => $this->layout->all(),
            'functions' => HomeSectionLayoutService::functions(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'sections' => ['present', 'array', 'max:50'],
            'sections.*.key' => [
                'required',
                'string',
                'distinct',
                'max:100',
                'regex:/\A(?:[a-z][a-z0-9_]*|custom_[a-z0-9_]+)\z/',
            ],
            'sections.*.source_key' => ['nullable', 'string', Rule::in(HomeSectionLayoutService::keys())],
            'sections.*.title' => ['required', 'string', 'max:120'],
        ]);

        foreach ($validated['sections'] as &$section) {
            $section['source_key'] ??= in_array($section['key'], HomeSectionLayoutService::keys(), true)
                ? $section['key']
                : null;
            if ($section['source_key'] === null) {
                throw ValidationException::withMessages([
                    'sections' => ['A function is required for every custom section.'],
                ]);
            }
        }
        unset($section);

        return V4Response::success([
            'sections' => $this->layout->replace($validated['sections']),
        ], 'Home sections updated.');
    }
}
