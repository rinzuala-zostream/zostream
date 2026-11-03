<?php

namespace Tests\Feature;

use App\Http\Controllers\HlsFolderController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class TrailerHlsIsolationTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/zostream-trailer-hls-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($this->root);
        config([
            'streaming.hls_root' => $this->root,
            // No conversion subprocess or external media requests in these fixtures.
            'streaming.mpd2hls_script' => $this->root.'/missing-script.js',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    public function test_trailer_never_reuses_or_overwrites_a_main_video_cache(): void
    {
        $main = $this->master('shared', 'main-video');
        $response = app(HlsFolderController::class)->check(Request::create('/', 'GET', [
            'url' => 'https://cdn.example/shared/trailer.mpd',
            'context' => 'trailer',
        ]));
        $this->assertSame(500, $response->getStatusCode());
        $payload = $response->getData(true);
        $this->assertSame('trailer', $payload['data']['context']);
        $this->assertNull($payload['data']['stream_url']);
        $this->assertNotSame(dirname($main), $payload['data']['full_path']);
        $this->assertSame('main-video', file_get_contents($main));
    }

    public function test_trailer_uses_isolated_cache_and_ordinary_playback_keeps_its_existing_path(): void
    {
        $source = 'https://cdn.example/shared/trailer.mpd';
        $main = $this->master('shared', 'main-video');
        $relative = '_trailers/'.hash('sha256', $source);
        $trailer = $this->master($relative, 'trailer-video');
        $controller = app(HlsFolderController::class);
        $response = $controller->check(Request::create('/', 'GET', ['url' => $source, 'context' => 'trailer']));
        $this->assertSame(200, $response->getStatusCode());
        $data = $response->getData(true)['data'];
        $this->assertSame($trailer, $data['master_m3u8']);
        $this->assertStringEndsWith('/hls/'.$relative.'/master.m3u8', $data['stream_url']);
        $this->assertSame('trailer', $data['context']);
        $ordinary = $controller->check(Request::create('/', 'GET', ['url' => $source]))->getData(true)['data'];
        $this->assertSame($main, $ordinary['master_m3u8']);
        $this->assertArrayNotHasKey('context', $ordinary);
        $this->assertSame('main-video', file_get_contents($main));
    }

    public function test_different_hosts_or_signed_sources_do_not_share_trailer_cache(): void
    {
        $sources = [
            'https://cdn.example/shared/trailer.mpd?token=a+b',
            'https://cdn.example/shared/trailer.mpd?token=other',
            'https://other.example/shared/trailer.mpd?token=a+b',
        ];
        $masters = [];
        foreach ($sources as $index => $source) {
            $path = $this->master('_trailers/'.hash('sha256', $source), 'trailer-'.$index);
            $response = app(HlsFolderController::class)->check(Request::create('/', 'GET', ['url' => $source, 'context' => 'trailer']));
            $this->assertSame(200, $response->getStatusCode());
            $masters[] = $response->getData(true)['data']['master_m3u8'];
            $this->assertSame($path, $masters[$index]);
            $this->assertSame('trailer-'.$index, file_get_contents($path));
        }
        $this->assertCount(3, array_unique($masters));
    }

    private function master(string $relative, string $contents): string
    {
        $directory = $this->root.'/'.$relative;
        File::ensureDirectoryExists($directory);
        $path = $directory.'/master.m3u8';
        file_put_contents($path, $contents);
        touch($path, time() + 60);
        return $path;
    }
}
