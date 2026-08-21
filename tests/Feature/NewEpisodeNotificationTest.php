<?php

namespace Tests\Feature;

use App\Http\Controllers\FCMNotificationController;
use App\Http\Controllers\New\EpisodeController;
use App\Models\New\Episode;
use App\Support\WebpImageUploader;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class NewEpisodeNotificationTest extends TestCase
{
    private string $originalConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalConnection = (string) config('database.default');
        config([
            'database.default' => 'episode_notification_testing',
            'database.connections.episode_notification_testing' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
        ]);
        DB::purge('episode_notification_testing');
        DB::reconnect('episode_notification_testing');

        Schema::create('movie', function (Blueprint $table) {
            $table->increments('num');
            $table->string('id')->unique();
            $table->string('title');
            $table->string('cover_img')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
        Schema::create('seasons', function (Blueprint $table) {
            $table->increments('num');
            $table->string('id')->unique();
            $table->unsignedInteger('movie_id');
        });
        Schema::create('episodes', function (Blueprint $table) {
            $table->increments('num');
            $table->string('id')->unique();
            $table->string('season_id');
            $table->unsignedInteger('episode_number');
            $table->string('title')->nullable();
            $table->string('thumbnail')->nullable();
            $table->string('status')->nullable();
            $table->boolean('isPayPerView')->default(false);
            $table->boolean('isPremium')->default(false);
            $table->decimal('amount')->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('views')->default(0);
            $table->text('description')->nullable();
            $table->date('release_date')->nullable();
            $table->timestamps();
        });
        Schema::create('video_urls', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->unsignedInteger('movie_id');
            $table->string('episode_id')->nullable();
            $table->string('quality')->nullable();
            $table->string('type')->nullable();
            $table->text('url');
            $table->timestamps();
        });

        DB::table('movie')->insert([
            'num' => 7,
            'id' => 'main-movie-key',
            'title' => 'Test Series',
            'cover_img' => 'https://example.com/cover.jpg',
        ]);
        DB::table('seasons')->insert(['id' => 'season-1', 'movie_id' => 7]);
    }

    protected function tearDown(): void
    {
        DB::disconnect('episode_notification_testing');
        config(['database.default' => $this->originalConnection]);

        parent::tearDown();
    }

    public function test_published_episode_create_uses_main_movie_id_as_notification_key(): void
    {
        $notifications = $this->expectedNotification('Test Series Premiere');

        $response = $this->controller($notifications)->store(new Request([
            'season_id' => 'season-1',
            'episode_number' => 1,
            'title' => 'Premiere',
            'status' => 'Published',
            'notification' => true,
        ]));

        $this->assertSame('success', $response->getData(true)['status']);
        $this->assertNotNull(DB::table('movie')->where('num', 7)->value('updated_at'));
    }

    public function test_published_episode_update_uses_main_movie_id_as_notification_key(): void
    {
        $episode = Episode::create([
            'id' => 'episode-1',
            'season_id' => 'season-1',
            'episode_number' => 1,
            'title' => 'Old title',
            'status' => 'Draft',
        ]);
        $notifications = $this->expectedNotification('Test Series Updated');

        $response = $this->controller($notifications)->update(new Request([
            'title' => 'Updated',
            'status' => 'Published',
            'notification' => true,
        ]), $episode->id);

        $this->assertSame('success', $response->getData(true)['status']);
    }

    public function test_unchecked_episode_notification_does_not_send(): void
    {
        $notifications = Mockery::mock(FCMNotificationController::class);
        $notifications->shouldNotReceive('sendToTopic');

        $this->controller($notifications)->store(new Request([
            'season_id' => 'season-1',
            'episode_number' => 2,
            'status' => 'Published',
            'notification' => false,
        ]));
    }

    public function test_draft_episode_does_not_update_main_movie_timestamp(): void
    {
        $notifications = Mockery::mock(FCMNotificationController::class);
        $notifications->shouldNotReceive('sendToTopic');

        $this->controller($notifications)->store(new Request([
            'season_id' => 'season-1',
            'episode_number' => 3,
            'status' => 'Draft',
            'notification' => false,
        ]));

        $this->assertNull(DB::table('movie')->where('num', 7)->value('updated_at'));
    }

    private function expectedNotification(string $title): FCMNotificationController
    {
        $notifications = Mockery::mock(FCMNotificationController::class);
        $notifications->shouldReceive('sendToTopic')
            ->once()
            ->with(
                'all',
                $title,
                'New episode streaming on Zo Stream',
                'https://example.com/cover.jpg',
                'main-movie-key',
            )
            ->andReturn(['success' => true, 'status' => 200]);

        return $notifications;
    }

    private function controller(FCMNotificationController $notifications): EpisodeController
    {
        return new EpisodeController(
            Mockery::mock(WebpImageUploader::class),
            $notifications,
        );
    }
}
