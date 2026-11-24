<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PublicPpvReportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        Schema::create('movie', function (Blueprint $t) {
            $t->increments('num');
            $t->string('id');
            $t->string('title');
            $t->string('poster')->nullable();
            $t->boolean('isPayPerView')->default(true);
            $t->boolean('isEnable')->default(true);
            $t->string('status')->default('Published');
            $t->decimal('ppv_amount')->default(100);
            $t->string('token')->default('secret-playback-token');
        });
        foreach (['seasons', 'episodes'] as $table) {
            Schema::create($table, function (Blueprint $t) use ($table) {
                $t->increments('num');
                $t->string('id');
                $t->string($table === 'seasons' ? 'movie_id' : 'season_id');
                $t->string('title');
                $t->string('poster')->nullable();
                $t->boolean('isPayPerView')->default(true);
                $t->string('status')->default('Published');
                $t->decimal('amount')->default(50);
            });
        }
        foreach (['n_payment_histories', 'ppv_payment'] as $table) {
            Schema::create($table, function (Blueprint $t) use ($table) {
                $t->increments('id');
                $t->string('movie_id');
                $t->string('user_id')->default('private-user@example.com');
                if ($table === 'n_payment_histories') {
                    $t->string('app_payment_type')->default('ppv');
                    $t->string('currency')->nullable()->default('INR');
                    $t->string('status')->default('success');
                    $t->decimal('amount')->default(80);
                    $t->dateTime('payment_date')->nullable();
                } else {
                    $t->string('payment_status')->default('captured');
                    $t->decimal('amount_paid')->default(100);
                    $t->dateTime('purchase_date')->nullable();
                }
                $t->timestamps();
            });
        }
        DB::table('movie')->insert(['num' => 10, 'id' => 'movie-one', 'title' => 'Test PPV']);
        $this->travelTo(now('UTC')->setDate(2026, 11, 24)->setTime(12, 0));
    }

    public function test_guest_can_view_only_public_summary_with_separate_currencies(): void
    {
        DB::table('n_payment_histories')->insert([
            ['movie_id' => 'movie-one', 'currency' => 'INR', 'status' => 'success', 'amount' => 80],
            ['movie_id' => 'movie-one', 'currency' => 'USD', 'status' => 'success', 'amount' => 2],
            ['movie_id' => 'movie-one', 'currency' => 'INR', 'status' => 'refunded', 'amount' => 80],
            ['movie_id' => 'movie-one', 'currency' => 'INR', 'status' => 'cancelled', 'amount' => 80],
            ['movie_id' => 'other', 'currency' => 'INR', 'status' => 'success', 'amount' => 999],
        ]);
        DB::table('ppv_payment')->insert(['movie_id' => '10']);

        $this->get('/ppv/movie/movie-one')->assertOk()
            ->assertViewHas('summary', fn ($s) => $s['purchases'] === 3 && $s['refunds'] === 1 && $s['cancelled'] === 1
                && $s['currencies'] === ['INR' => 180.0, 'USD' => 2.0])
            ->assertSee('INR 100.00')->assertDontSee('private-user@example.com')->assertDontSee('secret-playback-token')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->get('/ppv/movie/10')->assertOk();
    }

    public function test_period_filters_use_india_midnight_and_fallback_created_at(): void
    {
        config(['app.timezone' => 'UTC']);
        DB::table('n_payment_histories')->insert([
            ['movie_id' => 'movie-one', 'payment_date' => '2026-11-23 19:00:00', 'created_at' => null],
            ['movie_id' => 'movie-one', 'payment_date' => '2026-11-23 18:00:00', 'created_at' => null],
            ['movie_id' => 'movie-one', 'payment_date' => null, 'created_at' => '2026-11-24 01:00:00'],
            ['movie_id' => 'movie-one', 'payment_date' => '2026-10-01 00:00:00', 'created_at' => null],
        ]);
        $this->get('/ppv/movie/movie-one?period=today')->assertOk()->assertViewHas('summary', fn ($s) => $s['purchases'] === 2);
        $this->get('/ppv/movie/movie-one?period=month')->assertOk()->assertViewHas('summary', fn ($s) => $s['purchases'] === 3);
        $this->getJson('/ppv/movie/movie-one?period=invalid')->assertUnprocessable();
    }

    public function test_episode_counts_exclude_parent_season_and_numeric_id_collisions(): void
    {
        DB::table('seasons')->insert(['num' => 10, 'id' => 'season-one', 'movie_id' => '10', 'title' => 'Season one']);
        DB::table('episodes')->insert(['num' => 10, 'id' => 'episode-one', 'season_id' => 'season-one', 'title' => 'Episode one']);
        foreach (['episode-one', '10_episode-one', 'season-one', '10'] as $id) {
            DB::table('n_payment_histories')->insert(['movie_id' => $id]);
        }
        $this->get('/ppv/episode/episode-one')->assertOk()->assertViewHas('summary', fn ($s) => $s['purchases'] === 2);
        $this->get('/ppv/season/season-one')->assertOk()->assertViewHas('summary', fn ($s) => $s['purchases'] === 1);
        $this->get('/ppv/parent/10')->assertOk()->assertViewHas('summary', fn ($s) => $s['purchases'] === 4);
    }

    public function test_non_ppv_missing_and_invalid_types_return_not_found(): void
    {
        DB::table('movie')->where('num', 10)->update(['isPayPerView' => false]);
        $this->get('/ppv/movie/movie-one')->assertNotFound();
        $this->get('/ppv/movie/missing')->assertNotFound();
        $this->get('/ppv/unknown/10')->assertNotFound();
    }
}
