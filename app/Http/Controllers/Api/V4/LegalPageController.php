<?php

namespace App\Http\Controllers\Api\V4;

use App\Http\Controllers\Controller;
use App\Models\LegalPage;
use App\Support\Api\V4Response;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LegalPageController extends Controller
{
    public function publicIndex()
    {
        $pages = LegalPage::query()
            ->where('is_published', true)
            ->orderBy('sort_order')
            ->orderBy('title')
            ->get(['id', 'slug', 'eyebrow', 'title', 'effective_date', 'updated_at']);

        return V4Response::success($pages);
    }

    public function publicShow(string $slug)
    {
        $page = LegalPage::query()
            ->where('slug', $slug)
            ->where('is_published', true)
            ->first();

        if (! $page) {
            return V4Response::error('LEGAL_PAGE_NOT_FOUND', 'Legal page not found.', 404);
        }

        return V4Response::success($page);
    }

    public function index()
    {
        return V4Response::success(
            LegalPage::query()->orderBy('sort_order')->orderBy('title')->get()
        );
    }

    public function store(Request $request)
    {
        $page = LegalPage::create($request->validate($this->rules()));

        return V4Response::success($page, 'Legal page created.', status: 201);
    }

    public function show(LegalPage $legalPage)
    {
        return V4Response::success($legalPage);
    }

    public function update(Request $request, LegalPage $legalPage)
    {
        $legalPage->update($request->validate($this->rules($legalPage)));

        return V4Response::success($legalPage->fresh(), 'Legal page updated.');
    }

    private function rules(?LegalPage $page = null): array
    {
        return [
            'slug' => [
                $page ? 'sometimes' : 'required',
                'string',
                'max:120',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('legal_pages', 'slug')->ignore($page?->id),
            ],
            'eyebrow' => [$page ? 'sometimes' : 'nullable', 'nullable', 'string', 'max:160'],
            'title' => [$page ? 'sometimes' : 'required', 'string', 'max:180'],
            'effective_date' => [$page ? 'sometimes' : 'nullable', 'nullable', 'string', 'max:180'],
            'intro' => [$page ? 'sometimes' : 'nullable', 'nullable', 'string', 'max:3000'],
            'sections' => [$page ? 'sometimes' : 'required', 'array', 'min:1', 'max:40'],
            'sections.*.heading' => ['required_with:sections', 'string', 'max:220'],
            'sections.*.body' => ['required_with:sections', 'string', 'max:20000'],
            'is_published' => [$page ? 'sometimes' : 'nullable', 'boolean'],
            'sort_order' => [$page ? 'sometimes' : 'nullable', 'integer', 'min:0', 'max:10000'],
        ];
    }
}
