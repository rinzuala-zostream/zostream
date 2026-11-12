<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V4\DesktopReleaseUploadController;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DesktopReleaseUploadTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['filesystems.disks.r2.url' => 'https://downloads.example.test']);
        Storage::fake('r2', ['url' => 'https://downloads.example.test']);
    }

    private function upload(string $platform, ?UploadedFile $file = null)
    {
        return (new DesktopReleaseUploadController)(Request::create('/', 'POST', [], [], $file ? ['file' => $file] : []), $platform);
    }

    public static function installers(): array
    {
        return [
            ['macos_update', 'Zo Stream 1.2.3.dmg'], ['macos_update', 'ZoStream.pkg'], ['macos_update', 'ZoStream.zip'],
            ['windows_update', 'ZoStream.EXE'], ['windows_update', 'ZoStream.msi'], ['windows_update', 'ZoStream.msix'],
            ['windows_update', 'ZoStream.msixbundle'], ['windows_update', 'ZoStream.appx'],
            ['windows_update', 'ZoStream.appxbundle'], ['windows_update', 'ZoStream.zip'],
        ];
    }

    #[DataProvider('installers')]
    public function test_installer_upload_returns_a_permanent_https_download_url(string $platform, string $name): void
    {
        $response = $this->upload($platform, UploadedFile::fake()->create($name, 10, 'application/octet-stream'));
        $this->assertSame(201, $response->getStatusCode());
        $data = $response->getData(true)['data'];
        $this->assertSame($platform, $data['platform']);
        $this->assertStringStartsWith('https://downloads.example.test/app-releases/'.str_replace('_update', '', $platform).'/', $data['url']);
        $this->assertSame(10240, $data['size']);
        $this->assertSame($data['filename'], basename($data['url']));
        $path = ltrim(parse_url($data['url'], PHP_URL_PATH), '/');
        Storage::disk('r2')->assertExists($path);
        $this->assertCount(1, Storage::disk('r2')->allFiles());
    }

    public static function rejectedFiles(): array
    {
        return [
            ['update', 'ZoStream.apk'], ['ios_update', 'ZoStream.ipa'], ['tv_update', 'ZoStream.apk'],
            ['macos_update', 'ZoStream.exe'], ['windows_update', 'ZoStream.dmg'],
            ['windows_update', 'payload.exe.php'], ['macos_update', 'payload.php'],
        ];
    }

    #[DataProvider('rejectedFiles')]
    public function test_other_platforms_and_wrong_extensions_cannot_upload(string $platform, string $name): void
    {
        $this->assertSame(422, $this->upload($platform, UploadedFile::fake()->create($name, 10))->getStatusCode());
        $this->assertSame([], Storage::disk('r2')->allFiles());
    }

    public function test_missing_empty_and_oversized_files_are_rejected_without_writing(): void
    {
        $this->assertSame(422, $this->upload('macos_update')->getStatusCode());
        foreach ([0, 524289] as $kilobytes) {
            $this->assertSame(422, $this->upload('macos_update', UploadedFile::fake()->create('app.dmg', $kilobytes))->getStatusCode());
        }
        $this->assertSame([], Storage::disk('r2')->allFiles());
    }

    public function test_same_filename_uploads_never_overwrite_previous_releases(): void
    {
        $first = $this->upload('windows_update', UploadedFile::fake()->create('ZoStream.exe', 10))->getData(true)['data']['url'];
        $second = $this->upload('windows_update', UploadedFile::fake()->create('ZoStream.exe', 10))->getData(true)['data']['url'];
        $this->assertNotSame($first, $second);
        $this->assertCount(2, Storage::disk('r2')->allFiles());
    }

    public function test_a_missing_or_insecure_public_download_url_does_not_store_a_file(): void
    {
        foreach ([null, 'http://downloads.example.test'] as $url) {
            config(['filesystems.disks.r2.url' => $url]);
            $this->assertSame(500, $this->upload('macos_update', UploadedFile::fake()->create('app.pkg', 10))->getStatusCode());
        }
        $this->assertSame([], Storage::disk('r2')->allFiles());
    }

    public function test_storage_failure_never_returns_a_download_url(): void
    {
        $disk = Mockery::mock();
        $disk->shouldReceive('url')->once()->andReturn('https://downloads.example.test/app-releases/macos/app.pkg');
        $disk->shouldReceive('putFileAs')->once()->withArgs(function ($directory, $file, $name, $options) {
            $this->assertStringStartsWith('app-releases/macos/', $directory);
            $this->assertSame('application/octet-stream', $options['ContentType']);
            $this->assertSame('attachment; filename="app.pkg"', $options['ContentDisposition']);

            return $file instanceof UploadedFile && $name === 'app.pkg';
        })->andReturn(false);
        Storage::shouldReceive('disk')->once()->with('r2')->andReturn($disk);
        $response = $this->upload('macos_update', UploadedFile::fake()->create('app.pkg', 10));
        $this->assertSame(500, $response->getStatusCode());
        $this->assertNull($response->getData(true)['data']);
    }

    public function test_upload_endpoint_requires_a_signed_in_administrator(): void
    {
        $this->postJson('/api/v4/admin/app-releases/macos_update/upload')->assertUnauthorized();
        $this->assertSame([], Storage::disk('r2')->allFiles());
    }
}
