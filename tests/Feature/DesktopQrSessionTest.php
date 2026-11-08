<?php

namespace Tests\Feature;

use App\Http\Controllers\New\QRSessionController;
use Illuminate\Http\Request;
use Mockery;
use Tests\TestCase;

class DesktopQrSessionTest extends TestCase
{
    public function test_qr_creation_saves_the_desktop_identity_and_platform(): void
    {
        foreach (['windows', 'macos'] as $platform) {
            $controller = (new \ReflectionClass(QRSessionController::class))->newInstanceWithoutConstructor();
            $database = Mockery::mock();
            $reference = Mockery::mock();
            $database->shouldReceive('getReference')->once()
                ->withArgs(fn ($path) => str_starts_with($path, 'qr_sessions/'))
                ->andReturn($reference);
            $reference->shouldReceive('set')->once()->withArgs(fn (array $session) => $session['device_type'] === 'desktop'
                && $session['device_id'] === 'persistent-install-id'
                && $session['platform'] === $platform
                && $session['type'] === 'login'
            );
            (new \ReflectionProperty(QRSessionController::class, 'database'))->setValue($controller, $database);
            $request = Request::create('/', 'POST', [
                'device_type' => 'desktop', 'device_id' => 'persistent-install-id',
                'device_name' => 'My computer', 'type' => 'login',
            ]);
            $request->headers->set('X-Client-Platform', $platform);
            $response = $controller->create($request);
            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame('success', $response->getData(true)['status']);
        }
    }

    public function test_existing_tv_qr_payload_is_unchanged(): void
    {
        $controller = (new \ReflectionClass(QRSessionController::class))->newInstanceWithoutConstructor();
        $database = Mockery::mock();
        $reference = Mockery::mock();
        $database->shouldReceive('getReference')->once()->andReturn($reference);
        $reference->shouldReceive('set')->once()->withArgs(fn (array $session) => $session['device_type'] === 'tv' && ! array_key_exists('platform', $session)
        );
        (new \ReflectionProperty(QRSessionController::class, 'database'))->setValue($controller, $database);
        $response = $controller->create(Request::create('/', 'POST', ['device_type' => 'tv', 'device_id' => 'tv-id']));
        $this->assertSame(200, $response->getStatusCode());
    }
}
