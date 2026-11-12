<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V4\DesktopReleaseUploadController;
use App\Http\Controllers\New\AppUpdateController;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DesktopAppReleaseTest extends TestCase
{
    private function controller(array &$values): AppUpdateController
    {
        $controller = (new \ReflectionClass(AppUpdateController::class))->newInstanceWithoutConstructor();
        $database = Mockery::mock();
        $database->shouldReceive('getReference')->andReturnUsing(function ($key) use (&$values) {
            $reference = Mockery::mock();
            $reference->shouldReceive('getValue')->andReturnUsing(function () use (&$values, $key) {
                return $values[$key] ?? null;
            });
            $reference->shouldReceive('update')->andReturnUsing(function ($data) use (&$values, $key) {
                $values[$key] = array_merge($values[$key] ?? [], $data);
            });

            return $reference;
        });
        (new \ReflectionProperty(AppUpdateController::class, 'database'))->setValue($controller, $database);

        return $controller;
    }

    public static function platforms(): array
    {
        return [
            ['update', 'v_code', 101], ['ios_update', 'v_code', 102],
            ['tv_update', 'v', 103], ['lg_tv_update', 'version', '2.0'],
            ['sam_tv_update', 'version', '2.1'],
            ['windows_update', 'version', '0.2.0'], ['macos_update', 'version', '0.2.0-beta.1'],
        ];
    }

    #[DataProvider('platforms')]
    public function test_release_save_and_read_preserves_platform_contract(string $platform, string $field, int|string $version): void
    {
        $values = ['unrelated' => ['version' => 'keep-me']];
        $controller = $this->controller($values);
        $response = $controller->update(Request::create('/', 'PUT', [
            $field => $version, 'url' => 'https://downloads.example.test/zostream', 'force' => true, 'enabled' => false,
        ]), $platform);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($version, $values[$platform][$field]);
        $stored = $controller->show($platform)->getData(true)['data'];
        $this->assertSame($platform, $stored['platform']);
        $this->assertSame($version, $stored['version']);
        $this->assertFalse($stored['enabled']);
        $this->assertTrue($stored['force']);
        $this->assertSame(['version' => 'keep-me'], $values['unrelated']);
    }

    public function test_new_desktop_configuration_is_not_created_by_reading_it(): void
    {
        $values = ['tv_update' => ['v' => 42]];
        $controller = $this->controller($values);
        $this->assertSame(404, $controller->show('windows_update')->getStatusCode());
        $this->assertSame(['tv_update' => ['v' => 42]], $values);
    }

    public function test_uploaded_installer_url_is_published_only_when_the_release_is_saved(): void
    {
        config(['filesystems.disks.r2.url' => 'https://downloads.example.test']);
        Storage::fake('r2', ['url' => 'https://downloads.example.test']);
        $values = [];
        $controller = $this->controller($values);
        $response = (new DesktopReleaseUploadController)(Request::create('/', 'POST', [], [], [
            'file' => UploadedFile::fake()->create('ZoStream.dmg', 10),
        ]), 'macos_update');
        $this->assertSame(201, $response->getStatusCode());
        $url = $response->getData(true)['data']['url'];
        $this->assertSame([], $values);
        $saved = $controller->update(Request::create('/', 'PUT', [
            'version' => '1.2.3', 'url' => $url, 'force' => true, 'enabled' => true,
        ]), 'macos_update');
        $this->assertSame(200, $saved->getStatusCode());
        $this->assertSame($url, $controller->show('macos_update')->getData(true)['data']['url']);
        $this->assertArrayNotHasKey('windows_update', $values);
    }

    public function test_desktop_versions_and_forced_download_urls_are_validated_before_writing(): void
    {
        $values = [];
        $controller = $this->controller($values);
        foreach ([['version' => 'nonsense', 'url' => 'https://example.test/app'], ['version' => '0.2.0', 'url' => ''], ['version' => '0.2.0', 'url' => 'javascript:alert(1)']] as $input) {
            $response = $controller->update(Request::create('/', 'PUT', $input + ['force' => true]), 'macos_update');
            $this->assertSame(422, $response->getStatusCode());
        }
        $this->assertSame([], $values);
    }
}
