<?php

namespace Tests\Feature;

use App\Http\Controllers\FCMNotificationController;
use App\Http\Controllers\New\MovieController;
use App\Support\MpdDurationExtractor;
use App\Support\WebpImageUploader;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class NewMovieNotificationTest extends TestCase
{
    private string $originalConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalConnection = (string) config('database.default');
        config([
            'database.default' => 'movie_notification_testing',
            'database.connections.movie_notification_testing' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
        ]);
        DB::purge('movie_notification_testing');
        DB::reconnect('movie_notification_testing');

        Schema::create('movie', function (Blueprint $table) {
            $table->increments('num');
            $table->string('id')->unique();
            $table->string('title');
            $table->string('duration')->nullable();
            $table->string('status')->nullable();
            $table->date('create_date');
            $table->string('trailer')->default('');
            $table->string('cover_img')->nullable();
        });
    }

    protected function tearDown(): void
    {
        DB::disconnect('movie_notification_testing');
        config(['database.default' => $this->originalConnection]);

        parent::tearDown();
    }

    public function test_published_movie_sends_requested_push_notification(): void
    {
        $notifications = Mockery::mock(FCMNotificationController::class);
        $notifications->shouldReceive('sendToTopic')
            ->once()
            ->with(
                'all',
                'Test Movie',
                'Streaming on Zo Stream',
                '',
                Mockery::on(fn ($key) => is_string($key) && strlen($key) === 10),
            )
            ->andReturn(['success' => true, 'status' => 200]);

        $response = $this->controller($notifications)->store(new Request([
            'title' => 'Test Movie',
            'duration' => '120 min',
            'status' => 'Published',
            'notification' => true,
        ]));

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('success', $response->getData(true)['status']);
    }

    public function test_unchecked_notification_does_not_send_push(): void
    {
        $notifications = Mockery::mock(FCMNotificationController::class);
        $notifications->shouldNotReceive('sendToTopic');

        $response = $this->controller($notifications)->store(new Request([
            'title' => 'Silent Movie',
            'duration' => '90 min',
            'status' => 'Published',
            'notification' => false,
        ]));

        $this->assertSame(201, $response->getStatusCode());
    }

    private function controller(FCMNotificationController $notifications): MovieController
    {
        return new MovieController(
            Mockery::mock(WebpImageUploader::class),
            Mockery::mock(MpdDurationExtractor::class),
            $notifications,
        );
    }
}
