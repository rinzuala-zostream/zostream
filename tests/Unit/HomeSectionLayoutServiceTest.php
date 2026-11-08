<?php

namespace Tests\Unit;

use App\Services\HomeSectionLayoutService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class HomeSectionLayoutServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'home-section-testing');
        config()->set('database.connections.home-section-testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        DB::purge('home-section-testing');

        Schema::create('home_sections', function (Blueprint $table): void {
            $table->id();
            $table->string('section_key')->unique();
            $table->string('source_key')->nullable();
            $table->string('title');
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();
        });
    }

    public function test_it_replaces_only_the_home_layout_and_keeps_removed_sections_available(): void
    {
        $service = app(HomeSectionLayoutService::class);

        $service->replace([
            ['key' => 'trending_now', 'source_key' => 'trending_now', 'title' => 'Trending First'],
            ['key' => 'latest_update', 'source_key' => 'latest_update', 'title' => 'Fresh Today'],
        ]);

        $enabled = $service->enabled();
        $all = collect($service->all())->keyBy('key');

        $this->assertSame(['trending_now', 'latest_update'], array_column($enabled, 'key'));
        $this->assertSame('Trending First', $enabled[0]['title']);
        $this->assertFalse($all->get('top_picks_for_you')['is_enabled']);

        $service->replace([
            ['key' => 'custom_weekend_picks', 'source_key' => 'top_picks_for_you', 'title' => 'Weekend Picks'],
            ['key' => 'trending_now', 'source_key' => 'trending_now', 'title' => 'Trending First'],
        ]);

        $this->assertSame(
            ['custom_weekend_picks', 'trending_now'],
            array_column($service->enabled(), 'key')
        );
        $this->assertSame('top_picks_for_you', $service->enabled()[0]['source_key']);
    }
}
