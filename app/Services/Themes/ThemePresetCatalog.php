<?php

namespace App\Services\Themes;

use Carbon\CarbonImmutable;

final class ThemePresetCatalog
{
    public function __construct(private ThemeSchema $schema) {}

    public function all(): array
    {
        $definitions = [
            ['default', 'Default Zo Stream', 'Original brand colors, without card decorations.', ['#101014', '#E50914', '#27272A', '#FFFFFF'], 0, 'none', 'none', null],
            ['christmas', 'Christmas', 'Pine colors, gold lights and snowy card corners.', ['#101A16', '#B91C1C', '#176B48', '#D4AF37'], 80, 'snow', 'twinkle', ['12-01', '01-01']],
            ['new_year', 'New Year', 'Gold stars and celebration accents.', ['#101014', '#D4AF37', '#24243A', '#F5E6A8'], 85, 'stars', 'sparkle', ['12-31', '01-08']],
            ['valentine', 'Valentine', 'Rose surfaces and small heart corners.', ['#1B1018', '#E54878', '#752A50', '#F5A6C8'], 60, 'hearts', 'none', ['02-10', '02-15']],
            ['chapchar_kut', 'Chapchar Kut', 'Geometric woven accents; set the verified festival dates.', ['#17151B', '#C72C41', '#253D32', '#E8C67A'], 90, 'woven', 'geometric', null],
            ['easter', 'Easter', 'Lavender colors and soft floral corners; set yearly dates.', ['#17191C', '#9C8AD9', '#86B7A0', '#F2D5A0'], 75, 'petals', 'petals', null],
            ['independence_day', 'Independence Day', 'Saffron, white and green edge accents.', ['#101820', '#FF9933', '#138808', '#FFFFFF'], 70, 'tricolor', 'light_sweep', ['08-13', '08-16']],
            ['diwali', 'Diwali', 'Warm light and diya-style corners; set yearly dates.', ['#1B140F', '#F59E0B', '#8B4513', '#FFD700'], 75, 'lights', 'warm_glow', null],
            ['halloween', 'Halloween', 'Orange and violet with pumpkin corners.', ['#100D17', '#F97316', '#6D28D9', '#A3E635'], 55, 'pumpkin', 'none', ['10-25', '11-01']],
            ['pawl_kut', 'Pawl Kut', 'Harvest gold and grain accents; set verified dates.', ['#191710', '#D6A343', '#647A39', '#E8C878'], 90, 'grain', 'none', null],
            ['anniversary', 'Zo Stream Anniversary', 'Brand red and gold celebration stars; set the anniversary dates.', ['#101014', '#E50914', '#27272A', '#D4AF37'], 100, 'confetti', 'confetti', null],
            ['mim_kut', 'Mim Kut', 'Green and maize-inspired corners; set verified dates.', ['#111B16', '#789B4A', '#B78A43', '#E2C879'], 90, 'maize', 'none', null],
        ];
        $result = [];
        foreach ($definitions as [$key, $name, $description, $palette, $priority, $motif, $animation, $window]) {
            $document = $this->schema->defaults();
            $document['tokens'] = array_combine(['background', 'primary', 'secondary', 'accent'], $palette);
            $document['priority'] = $priority;
            $document['cards'] = ['enabled' => $motif !== 'none', 'preset' => $motif, 'opacity' => 0.8, 'movie_cards' => true, 'episode_cards' => true];
            $document['effects']['animation'] = ['enabled' => $animation !== 'none', 'preset' => $animation];
            if ($key === 'christmas') {
                $document['effects']['snowfall'] = ['enabled' => true, 'max_particles' => 20];
            }
            if ($window) {
                $document['schedules'] = [$this->nextWindow(...$window)];
            }
            $result[$key] = ['key' => $key, 'name' => $name, 'description' => $description,
                'requires_dates' => $window === null && $key !== 'default', 'document' => $document];
        }

        return $result;
    }

    private function nextWindow(string $start, string $end): array
    {
        $now = CarbonImmutable::now('Asia/Kolkata');
        // Include an ongoing window across the year boundary before choosing the next occurrence.
        foreach ([$now->year - 1, $now->year, $now->year + 1] as $year) {
            $from = CarbonImmutable::parse($year.'-'.$start.' 00:00:00', 'Asia/Kolkata');
            $to = CarbonImmutable::parse(($end <= $start ? $year + 1 : $year).'-'.$end.' 00:00:00', 'Asia/Kolkata');
            if ($to->greaterThan($now)) {
                return ['start_at' => $from->utc()->toIso8601String(), 'end_at' => $to->utc()->toIso8601String()];
            }
        }
        throw new \LogicException('No upcoming fixed theme window.');
    }
}
