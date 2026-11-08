<?php

namespace App\Console\Commands;

use App\Support\Chat\ChatDatabase;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

final class ChatRealtimeStatus extends Command
{
    protected $signature = 'chat:realtime-status {--probe : Test the public WebSocket upgrade without subscribing or sending messages}
        {--origin= : HTTPS API root used by the mobile app (defaults to APP_URL)}';

    protected $description = 'Read-only chat configuration, outbox and WebSocket handshake diagnostics; never prints credentials';

    public function handle(): int
    {
        $url = (string) config('chat.websocket_url');
        $parts = parse_url($url) ?: [];
        $key = (string) config('broadcasting.connections.reverb.key');
        $origin = (string) ($this->option('origin') ?: config('app.url'));
        $originHost = parse_url($origin, PHP_URL_HOST);
        $origins = config('reverb.apps.apps.0.allowed_origins', []);
        $validUrl = ($parts['scheme'] ?? '') === 'wss' && ! empty($parts['host'])
            && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['fragment']);
        $keyMatches = $key !== '' && ($parts['path'] ?? '') === '/app/'.rawurlencode($key);
        $checks = [
            'Chat enabled' => (bool) config('chat.enabled'),
            'Public WebSocket URL uses WSS' => $validUrl,
            'WebSocket URL matches configured public app key' => $keyMatches,
            'Reverb app ID configured' => filled(config('broadcasting.connections.reverb.app_id')),
            'Reverb secret configured' => filled(config('broadcasting.connections.reverb.secret')),
            'Internal publish host configured' => filled(config('broadcasting.connections.reverb.options.host')),
            'Native API origin allowed' => $originHost && collect($origins)->contains(fn ($allowed) => Str::is($allowed, $originHost)),
        ];
        $this->table(['Check', 'Result'], collect($checks)->map(fn ($ok, $label) => [$label, $ok ? 'OK' : 'FAIL'])->all());
        $ready = ! in_array(false, array_map('boolval', $checks), true);
        $this->line('Public WebSocket host: '.($parts['host'] ?? '(missing)'));
        $this->line('Native API origin host: '.($originHost ?: '(missing)'));
        $this->line('Check the API root currently used by the apps, including any remote-config override.');

        try {
            if (! ChatDatabase::schema()->hasTable('chat_outbox')) {
                $this->error('chat_outbox is missing. Apply the chat migrations.');
                $ready = false;
            } else {
                $pending = ChatDatabase::connection()->table('chat_outbox')->whereNull('delivered_at');
                $this->line('Pending broadcasts: '.(clone $pending)->count());
                $oldest = (clone $pending)->min('created_at');
                if ($oldest && Carbon::parse($oldest)->lt(now()->subSeconds(10))) {
                    $this->warn('Broadcasts are overdue. Check the supervised chat:deliver process, its logs and internal Reverb publish endpoint.');
                    $ready = false;
                }
                $this->line('An empty outbox alone does not verify that chat:deliver is running.');
            }
        } catch (\Throwable) {
            $this->error('Unable to inspect chat storage. Check database connectivity and chat migrations.');
            $ready = false;
        }

        if ($this->option('probe')) {
            if (! $validUrl || ! $keyMatches || ! $originHost || parse_url($origin, PHP_URL_SCHEME) !== 'https') {
                $this->error('Fix the WSS URL/app key and provide --origin=https://your-api-host before probing.');

                return self::FAILURE;
            }
            $ready = $this->probe('https://'.substr($url, 6), $origin) && $ready;
        }

        return $ready ? self::SUCCESS : self::FAILURE;
    }

    private function probe(string $url, string $origin): bool
    {
        $nonce = base64_encode(random_bytes(16));
        try {
            $response = Http::connectTimeout(3)->timeout(8)->withOptions(['stream' => true, 'allow_redirects' => false])
                ->withHeaders(['Origin' => $origin, 'Connection' => 'Upgrade', 'Upgrade' => 'websocket',
                    'Sec-WebSocket-Version' => '13', 'Sec-WebSocket-Key' => $nonce])->get($url);
            $upgraded = $response->status() === 101
                && strtolower($response->header('Upgrade')) === 'websocket'
                && hash_equals(base64_encode(sha1($nonce.'258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true)), $response->header('Sec-WebSocket-Accept'));
            $this->line('Public WebSocket upgrade: HTTP '.$response->status().($upgraded ? ' (OK)' : ' (FAIL)'));
            $response->toPsrResponse()->getBody()->close();
            if (! $upgraded) {
                $this->error('Check DNS/TLS, the /app/ Upgrade proxy, allowed Origin, matching Reverb app credentials and the reverb:start process.');
            }

            return $upgraded;
        } catch (\Throwable) {
            // Transport exceptions can contain the URL/public key or proxy configuration.
            $this->error('WebSocket upgrade could not complete. Check DNS/TLS, proxy and Reverb availability.');

            return false;
        }
    }
}
