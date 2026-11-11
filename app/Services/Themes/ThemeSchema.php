<?php

namespace App\Services\Themes;

use App\Models\AppThemeAsset;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ThemeSchema
{
    public const PLATFORMS = ['web', 'android', 'ios', 'android-tv', 'webos', 'tizen', 'windows', 'macos'];

    public const PRESETS = ['none', 'twinkle', 'sparkle', 'geometric', 'petals', 'light_sweep', 'warm_glow', 'confetti'];

    public const ASSET_KEYS = ['header', 'decoration'];

    public function defaults(): array
    {
        return [
            'priority' => 0,
            'platforms' => self::PLATFORMS,
            'tokens' => ['background' => '#101014', 'primary' => '#E50914', 'secondary' => '#27272A', 'accent' => '#FFFFFF'],
            'effects' => ['snowfall' => ['enabled' => false, 'max_particles' => 15], 'animation' => ['enabled' => false, 'preset' => 'none']],
            'assets' => [],
            'schedules' => [],
        ];
    }

    public function christmas(): array
    {
        return array_replace_recursive($this->defaults(), [
            'priority' => 80,
            'tokens' => ['background' => '#101A16', 'primary' => '#B91C1C', 'secondary' => '#176B48', 'accent' => '#D4AF37'],
            'effects' => ['snowfall' => ['enabled' => true, 'max_particles' => 20], 'animation' => ['enabled' => true, 'preset' => 'twinkle']],
        ]);
    }

    public function validate(array $document, int $themeId): array
    {
        $data = Validator::make(['document' => $document], [
            'document' => 'required|array:priority,platforms,tokens,effects,assets,schedules',
            'document.priority' => 'required|integer|min:0|max:1000',
            'document.platforms' => 'required|array|min:1|max:8',
            'document.platforms.*' => ['required', 'distinct', Rule::in(self::PLATFORMS)],
            'document.tokens' => 'required|array:background,primary,secondary,accent',
            'document.tokens.background' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/D'],
            'document.tokens.primary' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/D'],
            'document.tokens.secondary' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/D'],
            'document.tokens.accent' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/D'],
            'document.effects' => 'required|array:snowfall,animation',
            'document.effects.snowfall' => 'required|array:enabled,max_particles',
            'document.effects.snowfall.enabled' => 'required|boolean',
            'document.effects.snowfall.max_particles' => 'required|integer|min:0|max:35',
            'document.effects.animation' => 'required|array:enabled,preset',
            'document.effects.animation.enabled' => 'required|boolean',
            'document.effects.animation.preset' => ['required', Rule::in(self::PRESETS)],
            'document.assets' => 'present|array:header,decoration',
            'document.assets.*' => 'required|integer',
            'document.schedules' => 'present|array|max:30',
            'document.schedules.*' => 'required|array:start_at,end_at',
            'document.schedules.*.start_at' => ['required', 'date', 'regex:/(Z|[+-][0-9]{2}:[0-9]{2})$/D'],
            'document.schedules.*.end_at' => ['required', 'date', 'regex:/(Z|[+-][0-9]{2}:[0-9]{2})$/D'],
        ])->validate()['document'];

        foreach ($data['schedules'] as $index => &$schedule) {
            $start = CarbonImmutable::parse($schedule['start_at'])->utc();
            $end = CarbonImmutable::parse($schedule['end_at'])->utc();
            if ($end->lessThanOrEqualTo($start)) {
                throw ValidationException::withMessages(["document.schedules.$index.end_at" => 'End must be after start (end is exclusive).']);
            }
            $schedule = ['start_at' => $start->toIso8601String(), 'end_at' => $end->toIso8601String()];
        }
        unset($schedule);
        foreach ($data['assets'] as $key => $id) {
            if (! AppThemeAsset::where('theme_id', $themeId)->where('asset_key', $key)->whereKey($id)->exists()) {
                throw ValidationException::withMessages(["document.assets.$key" => 'Select an uploaded asset belonging to this theme and slot.']);
            }
        }
        $data['priority'] = (int) $data['priority'];
        $data['effects']['snowfall']['enabled'] = (bool) $data['effects']['snowfall']['enabled'];
        $data['effects']['snowfall']['max_particles'] = (int) $data['effects']['snowfall']['max_particles'];
        $data['effects']['animation']['enabled'] = (bool) $data['effects']['animation']['enabled'];

        return $data;
    }

    public function payload(string $key, int $version, array $document, string $platform): array
    {
        $effects = $document['effects'];
        $tv = in_array($platform, ['android-tv', 'webos', 'tizen'], true);
        $effects['snowfall']['max_particles'] = min($effects['snowfall']['max_particles'], $tv ? 10 : ($platform === 'web' ? 35 : 25));
        // TV starts with static decoration until its renderer has passed performance QA.
        if ($tv) {
            $effects['snowfall']['enabled'] = false;
            $effects['animation']['enabled'] = false;
        }
        $assets = [];
        foreach ($document['assets'] as $slot => $id) {
            $asset = AppThemeAsset::find($id);
            if ($asset) {
                $assets[$slot] = ['url' => $asset->url, 'checksum' => $asset->checksum, 'width' => $asset->width, 'height' => $asset->height];
            }
        }

        return [
            'schema_version' => 1, 'theme_key' => $key, 'version' => $version,
            'tokens' => $document['tokens'], 'effects' => $effects, 'assets' => (object) $assets,
            'decoration_scope' => 'home',
        ];
    }
}
