<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V4\AdminRealtimeConfigController;
use Illuminate\Http\Request;
use Kreait\Firebase\Contract\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DesktopWarningConfigTest extends TestCase
{
    #[DataProvider('platforms')]
    public function test_warning_save_accepts_desktop_and_existing_platforms(string $platform): void
    {
        $data = ['platform' => $platform, 'txt' => 'Scheduled maintenance', 'isShow' => true,
            'isCancelable' => true, 'isShowInLatest' => false];
        $history = [];
        $stack = \GuzzleHttp\HandlerStack::create(new \GuzzleHttp\Handler\MockHandler([
            new \GuzzleHttp\Psr7\Response(200, [], '{}'),
        ]));
        $stack->push(\GuzzleHttp\Middleware::history($history));
        $url = 'https://fixture.firebaseio.com';
        $firebaseApi = new \Kreait\Firebase\Database\ApiClient(
            new \GuzzleHttp\Client(['handler' => $stack]),
            \Kreait\Firebase\Database\UrlBuilder::create($url),
            new \Kreait\Firebase\Exception\DatabaseApiExceptionConverter(new \Kreait\Firebase\Http\ErrorResponseParser()),
        );
        $database = new \Kreait\Firebase\Database(new \GuzzleHttp\Psr7\Uri($url), $firebaseApi);
        $controller = new class($database) extends AdminRealtimeConfigController {
            public function __construct(private Database $fixture) {}
            protected function database(): Database { return $this->fixture; }
        };
        $response = $controller->saveWarning(Request::create('/', 'PUT', $data));
        $this->assertSame($platform, $response->getData(true)['data']['platform']);
        $this->assertCount(1, $history);
        $this->assertSame('PUT', $history[0]['request']->getMethod());
        $this->assertSame('/warning', $history[0]['request']->getUri()->getPath());
        $this->assertEquals($data, json_decode((string) $history[0]['request']->getBody(), true));
    }

    public static function platforms(): array
    {
        return [['all'], ['android'], ['ios'], ['macos'], ['windows']];
    }
}
