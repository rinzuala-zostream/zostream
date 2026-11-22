<?php

namespace App\Services\Engagement;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class EngagementTemplates
{
    public const DEFAULTS = [
        'continue_watching' => ['label' => 'Continue watching', 'body' => '{title} kha i en zawh lo va. En chhunzawm leh ang!'],
        'watchlist' => ['label' => 'Watchlist', 'body' => 'I watchlist-a {title} kha a nghak reng che. Lo en teh!'],
        'unused_subscription' => ['label' => 'Unused subscription', 'body' => 'I Zo Stream plan nen {title} hi lo en teh!'],
        'comeback' => ['label' => 'Comeback', 'body' => 'En tur tha a awm e! {title} hi i tan kan thlang.'],
        'search_interest' => ['label' => 'Search interest', 'body' => 'I zawng nen inmil tur {title} hi lo en teh!'],
        'post_watch' => ['label' => 'Post-watch discovery', 'body' => 'I en tawh kha i duh chuan, {title} pawh hi lo en teh!'],
    ];

    public function all(): array
    {
        // Defaults also allow code deployment before the settings migration.
        $saved = Schema::hasTable('engagement_rules') ? DB::table('engagement_rules')->get()->keyBy('rule') : collect();
        $rules = [];
        foreach (self::DEFAULTS as $key => $default) {
            $row = $saved->get($key);
            $rules[$key] = ['rule' => $key, 'label' => $default['label'], 'enabled' => $row ? (bool) $row->enabled : true,
                'title_template' => filled($row?->title_template) ? $row->title_template : 'Zo Stream',
                'body_template' => filled($row?->body_template) ? $row->body_template : $default['body']];
        }

        return $rules;
    }

    public function render(string $rule, string $title): array
    {
        $settings = $this->all()[$rule];

        return ['title' => str_replace('{title}', $title, $settings['title_template']),
            'body' => str_replace('{title}', $title, $settings['body_template'])];
    }
}
