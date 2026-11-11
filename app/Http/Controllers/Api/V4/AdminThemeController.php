<?php

namespace App\Http\Controllers\Api\V4;

use App\Http\Controllers\Controller;
use App\Models\AppTheme;
use App\Models\AppThemeAsset;
use App\Models\AppThemeAuditLog;
use App\Models\AppThemeOverride;
use App\Models\AppThemeVersion;
use App\Services\Themes\ThemeResolver;
use App\Services\Themes\ThemeSchema;
use App\Support\Api\V4Response;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminThemeController extends Controller
{
    public function __construct(private ThemeSchema $schema) {}

    public function index(Request $request, ThemeResolver $resolver)
    {
        $platform = $request->query('platform', 'web');
        $request->validate(['platform' => ['sometimes', Rule::in(ThemeSchema::PLATFORMS)]]);

        return V4Response::success([
            'themes' => AppTheme::orderBy('name')->get(),
            'active' => $resolver->resolve($platform),
            'override' => AppThemeOverride::whereNull('cancelled_at')->where('expires_at', '>', now('UTC'))->latest('id')->first(),
            'templates' => ['default' => $this->schema->defaults(), 'christmas' => $this->schema->christmas()],
            'platforms' => ThemeSchema::PLATFORMS, 'presets' => ThemeSchema::PRESETS,
        ])->header('Cache-Control', 'no-store');
    }

    public function show(AppTheme $theme)
    {
        return V4Response::success([
            'theme' => $theme,
            'versions' => $theme->versions()->get(),
            'assets' => AppThemeAsset::where('theme_id', $theme->id)->latest('id')->get(),
            'audit' => AppThemeAuditLog::where('theme_id', $theme->id)->latest('id')->limit(100)->get(),
        ])->header('Cache-Control', 'no-store');
    }

    public function audit()
    {
        return V4Response::success(AppThemeAuditLog::latest('id')->paginate(50))->header('Cache-Control', 'no-store');
    }

    public function store(Request $request)
    {
        $input = $request->validate([
            'key' => ['required', 'string', 'max:80', 'regex:/^[a-z][a-z0-9_]*$/D', 'not_in:default', 'unique:app_themes,key'],
            'name' => 'required|string|max:120',
            'document' => 'required|array',
        ]);
        $document = $this->schema->validate($input['document'], 0);
        $theme = DB::transaction(function () use ($request, $input, $document) {
            $theme = AppTheme::create(['key' => $input['key'], 'name' => $input['name'], 'draft' => $document, 'created_by' => $this->actor($request)]);
            $this->log($request, 'create', $theme->id, null, $theme->toArray());

            return $theme;
        });

        return V4Response::success($theme, status: 201);
    }

    public function update(Request $request, AppTheme $theme)
    {
        $input = $request->validate(['revision' => 'required|integer|min:1', 'name' => 'required|string|max:120', 'document' => 'required|array']);
        $document = $this->schema->validate($input['document'], $theme->id);

        return $this->mutate($request, $theme, function (AppTheme $locked) use ($request, $input, $document) {
            $before = $locked->toArray();
            $locked->update(['name' => $input['name'], 'draft' => $document, 'revision' => $locked->revision + 1]);
            $this->log($request, 'save_draft', $locked->id, $before, $locked->toArray());

            return V4Response::success($locked);
        });
    }

    public function preview(Request $request, AppTheme $theme)
    {
        $input = $request->validate(['revision' => 'required|integer|min:1', 'platform' => ['required', Rule::in(ThemeSchema::PLATFORMS)]]);
        if ($input['revision'] != $theme->revision) {
            return $this->conflict();
        }
        $document = $this->schema->validate($theme->draft, $theme->id);

        return V4Response::success([
            'preview' => $this->schema->payload($theme->key, 0, $document, $input['platform']),
            'preview_token' => $this->previewToken($theme),
            'platform_enabled' => in_array($input['platform'], $document['platforms'], true),
        ])->header('Cache-Control', 'no-store');
    }

    public function publish(Request $request, AppTheme $theme)
    {
        $request->validate(['revision' => 'required|integer|min:1', 'preview_token' => 'required|string', 'approved' => 'required|accepted', 'reason' => 'required|string|max:500']);

        return $this->mutate($request, $theme, function (AppTheme $locked) use ($request) {
            if (! hash_equals($this->previewToken($locked), $request->string('preview_token')->toString())) {
                return V4Response::error('PREVIEW_REQUIRED', 'Preview and approve the current saved draft before publishing.', 409);
            }
            $document = $this->schema->validate($locked->draft, $locked->id);
            $this->checkAssets($document);
            $before = $locked->toArray();
            $version = $this->createVersion($request, $locked, $document);
            $this->log($request, 'publish', $locked->id, $before, $version->toArray());

            return V4Response::success($locked->refresh());
        });
    }

    public function unpublish(Request $request, AppTheme $theme)
    {
        $request->validate(['revision' => 'required|integer|min:1', 'reason' => 'required|string|max:500']);

        return $this->mutate($request, $theme, function (AppTheme $locked) use ($request) {
            $before = $locked->toArray();
            $locked->update(['published_version_id' => null, 'revision' => $locked->revision + 1]);
            AppThemeOverride::whereIn('version_id', $locked->versions()->select('id'))->whereNull('cancelled_at')->update(['cancelled_at' => now('UTC')]);
            $this->log($request, 'unpublish', $locked->id, $before, $locked->toArray());

            return V4Response::success($locked);
        });
    }

    public function rollback(Request $request, AppTheme $theme)
    {
        $input = $request->validate(['revision' => 'required|integer|min:1', 'version_id' => 'required|integer', 'approved' => 'required|accepted', 'reason' => 'required|string|max:500']);

        return $this->mutate($request, $theme, function (AppTheme $locked) use ($request, $input) {
            $old = $locked->versions()->findOrFail($input['version_id']);
            $document = $this->schema->validate($old->document, $locked->id);
            $this->checkAssets($document);
            $before = $locked->toArray();
            $version = $this->createVersion($request, $locked, $document);
            $this->log($request, 'rollback', $locked->id, $before, $version->toArray());

            return V4Response::success($locked->refresh());
        });
    }

    public function override(Request $request)
    {
        $input = $request->validate([
            'theme_id' => 'present|nullable|integer|exists:app_themes,id',
            'expires_at' => ['required', 'date', 'regex:/(Z|[+-][0-9]{2}:[0-9]{2})$/D', 'after:now'],
            'reason' => 'required|string|max:500',
        ]);
        $expiry = CarbonImmutable::parse($input['expires_at'])->utc();
        if ($expiry->greaterThan(CarbonImmutable::now('UTC')->addDays(31))) {
            throw ValidationException::withMessages(['expires_at' => 'Override must expire within 31 days.']);
        }

        return DB::transaction(function () use ($request, $input, $expiry) {
            $this->lockControl();
            $theme = isset($input['theme_id']) ? AppTheme::findOrFail($input['theme_id']) : null;
            if ($theme && ! $theme->published_version_id) {
                throw ValidationException::withMessages(['theme_id' => 'Publish the theme before applying an override.']);
            }
            $before = AppThemeOverride::whereNull('cancelled_at')->where('expires_at', '>', now('UTC'))->get()->toArray();
            AppThemeOverride::whereNull('cancelled_at')->update(['cancelled_at' => now('UTC')]);
            $override = AppThemeOverride::create([
                'version_id' => $theme?->published_version_id, 'starts_at' => now('UTC'), 'expires_at' => $expiry,
                'actor_id' => $this->actor($request), 'reason' => $input['reason'],
            ]);
            $this->log($request, $theme ? 'override' : 'restore_default', $theme?->id, $before, $override->toArray());

            return V4Response::success($override, status: 201);
        });
    }

    public function clearOverride(Request $request)
    {
        $request->validate(['reason' => 'required|string|max:500']);

        return DB::transaction(function () use ($request) {
            $this->lockControl();
            $before = AppThemeOverride::whereNull('cancelled_at')->where('expires_at', '>', now('UTC'))->get()->toArray();
            AppThemeOverride::whereNull('cancelled_at')->update(['cancelled_at' => now('UTC')]);
            $this->log($request, 'resume_schedule', null, $before, null);

            return V4Response::success(null, 'Automatic schedules resumed.');
        });
    }

    public function upload(Request $request)
    {
        $input = $request->validate([
            'theme_id' => 'required|integer|exists:app_themes,id',
            'asset_key' => ['required', Rule::in(ThemeSchema::ASSET_KEYS)],
            'image' => 'required|file|image|mimes:png,jpg,jpeg,webp|max:2048|dimensions:max_width=4096,max_height=4096',
        ]);
        $file = $request->file('image');
        $dimensions = @getimagesize($file->getRealPath());
        if (! $dimensions || ! in_array($dimensions['mime'], ['image/png', 'image/jpeg', 'image/webp'], true)) {
            throw ValidationException::withMessages(['image' => 'Upload a valid PNG, JPEG or WebP image.']);
        }
        $path = 'themes/'.$input['theme_id'].'/'.Str::uuid().'.'.$file->extension();
        $disk = Storage::disk('r2');
        if (! $disk->put($path, file_get_contents($file->getRealPath()), ['ContentType' => $dimensions['mime'], 'CacheControl' => 'public, max-age=31536000, immutable'])) {
            throw ValidationException::withMessages(['image' => 'Asset upload failed. Try again.']);
        }
        try {
            $asset = DB::transaction(function () use ($request, $input, $file, $dimensions, $path, $disk) {
                $asset = AppThemeAsset::create([
                    'theme_id' => $input['theme_id'], 'asset_key' => $input['asset_key'], 'path' => $path, 'url' => $disk->url($path),
                    'checksum' => hash_file('sha256', $file->getRealPath()), 'width' => $dimensions[0], 'height' => $dimensions[1],
                    'size_bytes' => $file->getSize(), 'created_at' => now('UTC'),
                ]);
                $this->log($request, 'upload_asset', $asset->theme_id, null, $asset->toArray());

                return $asset;
            });
        } catch (\Throwable $exception) {
            $disk->delete($path);
            throw $exception;
        }

        return V4Response::success($asset, status: 201);
    }

    private function createVersion(Request $request, AppTheme $theme, array $document): AppThemeVersion
    {
        $version = AppThemeVersion::create([
            'theme_id' => $theme->id, 'number' => ((int) $theme->versions()->max('number')) + 1,
            'document' => $document, 'published_by' => $this->actor($request), 'published_at' => now('UTC'),
        ]);
        foreach ($document['schedules'] as $schedule) {
            $version->schedules()->create(['start_at' => CarbonImmutable::parse($schedule['start_at'])->utc(), 'end_at' => CarbonImmutable::parse($schedule['end_at'])->utc()]);
        }
        $theme->update(['published_version_id' => $version->id, 'draft' => $document, 'revision' => $theme->revision + 1]);

        return $version;
    }

    private function checkAssets(array $document): void
    {
        foreach ($document['assets'] as $id) {
            $asset = AppThemeAsset::findOrFail($id);
            if (! Storage::disk('r2')->exists($asset->path)) {
                throw ValidationException::withMessages(['document.assets' => 'An uploaded asset is missing. Replace it before publishing.']);
            }
        }
    }

    private function previewToken(AppTheme $theme): string
    {
        return hash_hmac('sha256', $theme->id.':'.$theme->revision.':'.json_encode($theme->draft), (string) config('app.key'));
    }

    private function mutate(Request $request, AppTheme $theme, callable $callback)
    {
        return DB::transaction(function () use ($request, $theme, $callback) {
            $this->lockControl();
            $locked = AppTheme::whereKey($theme->id)->lockForUpdate()->firstOrFail();
            if ($locked->revision !== (int) $request->input('revision')) {
                return $this->conflict();
            }

            return $callback($locked);
        });
    }

    private function lockControl(): void
    {
        DB::table('app_theme_controls')->where('id', 1)->lockForUpdate()->first();
        DB::table('app_theme_controls')->where('id', 1)->increment('revision');
    }

    private function conflict()
    {
        return V4Response::error('THEME_REVISION_CONFLICT', 'This theme changed. Reload it before continuing.', 409);
    }

    private function actor(Request $request): string
    {
        return (string) $request->input('auth_user_id');
    }

    private function log(Request $request, string $action, ?int $themeId, ?array $before, ?array $after): void
    {
        AppThemeAuditLog::create([
            'theme_id' => $themeId, 'actor_id' => $this->actor($request), 'action' => $action,
            'before_json' => $before, 'after_json' => $after, 'reason' => $request->input('reason'), 'created_at' => now('UTC'),
        ]);
    }
}
