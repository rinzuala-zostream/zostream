<?php

namespace App\Http\Controllers\Api\V4;

use App\Http\Controllers\Controller;
use App\Support\Api\V4Response;
use Illuminate\Http\Request;
use Kreait\Firebase\Database;
use Kreait\Firebase\Factory;

class AdminRealtimeConfigController extends Controller
{
    public function warning()
    {
        return V4Response::success($this->database()->getReference('warning')->getValue());
    }

    public function saveWarning(Request $request)
    {
        $data = $request->validate([
            'txt' => ['required', 'string', 'max:5000'],
            'platform' => ['required', 'in:all,android,ios'],
            'isShow' => ['required', 'boolean'],
            'isCancelable' => ['required', 'boolean'],
            'isShowInLatest' => ['required', 'boolean'],
        ]);
        $this->database()->getReference('warning')->set($data);

        return V4Response::success($data, 'Warning configuration saved.');
    }

    public function deleteWarning()
    {
        $this->database()->getReference('warning')->remove();

        return V4Response::success(null, 'Warning configuration deleted.');
    }

    public function textScroll()
    {
        return V4Response::success($this->database()->getReference('text_scroll')->getValue());
    }

    public function saveTextScroll(Request $request)
    {
        $data = $request->validate([
            'text' => ['required', 'string', 'max:5000'],
            'show' => ['required', 'boolean'],
        ]);
        $reference = $this->database()->getReference('text_scroll');
        $existing = (array) ($reference->getValue() ?? []);
        $value = array_merge($existing, $data, [
            'created_at' => $existing['created_at'] ?? now()->getTimestampMs(),
            'updated_at' => now()->getTimestampMs(),
        ]);
        $reference->set($value);

        return V4Response::success($value, 'Scrolling text saved.');
    }

    public function deleteTextScroll()
    {
        $this->database()->getReference('text_scroll')->remove();

        return V4Response::success(null, 'Scrolling text deleted.');
    }

    public function amazonIap()
    {
        $value = (array) ($this->database()
            ->getReference('payment_features/amazon_tv')
            ->getValue() ?? []);

        return V4Response::success(array_merge(['iap_enabled' => false], $value));
    }

    public function saveAmazonIap(Request $request)
    {
        $data = $request->validate([
            'iap_enabled' => ['required', 'boolean'],
        ]);
        $value = array_merge($data, [
            'updated_at' => now()->toIso8601String(),
        ]);
        $this->database()->getReference('payment_features/amazon_tv')->set($value);

        return V4Response::success($value, 'Amazon TV IAP configuration saved.');
    }

    public function officialClients()
    {
        $value = (array) ($this->database()->getReference('official_client_configs')->getValue() ?? []);
        $items = [];
        foreach ($value as $platform => $configs) {
            foreach ((array) $configs as $id => $config) {
                $items[] = array_merge((array) $config, ['id' => $id, 'platform' => $config['platform'] ?? $platform]);
            }
        }

        return V4Response::success($items);
    }

    public function saveOfficialClient(Request $request, ?string $id = null)
    {
        $data = $request->validate([
            'platform' => ['required', 'string', 'max:60', 'regex:/^[a-zA-Z0-9_-]+$/'],
            'name' => ['required', 'string', 'max:160'],
            'enabled' => ['required', 'boolean'],
            'verification_enabled' => ['sometimes', 'boolean'],
            'verification_mode' => ['required', 'string', 'max:80'],
            'app_identifier' => ['nullable', 'string', 'max:255'],
            'certificate_sha256' => ['nullable'],
            'team_id' => ['nullable', 'string', 'max:255'],
            'key_id' => ['nullable', 'string', 'max:255'],
            'build_id' => ['nullable', 'string', 'max:255'],
            'min_version' => ['nullable', 'string', 'max:80'],
            'latest_version' => ['nullable', 'string', 'max:80'],
            'api_base_url' => ['nullable', 'url', 'max:2048'],
            'api_version' => ['nullable', 'string', 'max:40'],
            'allowed_origins' => ['nullable', 'array'],
            'allowed_origins.*' => ['string', 'max:2048'],
            'metadata' => ['nullable', 'array'],
        ]);
        $platform = strtolower(str_replace('_', '-', $data['platform']));
        $id ??= $this->database()->getReference("official_client_configs/{$platform}")->push()->getKey();
        abort_unless($id, 500, 'Could not create official client key.');
        $this->removeOfficialClient($id);
        $value = array_merge(
            ['verification_enabled' => true],
            $data,
            ['platform' => $platform, 'updated_at' => now()->toIso8601String()]
        );
        $this->database()->getReference("official_client_configs/{$platform}/{$id}")->set($value);

        return V4Response::success(array_merge($value, ['id' => $id]), 'Official client saved.');
    }

    public function deleteOfficialClient(string $id)
    {
        $this->removeOfficialClient($id);

        return V4Response::success(null, 'Official client deleted.');
    }

    private function removeOfficialClient(string $id): void
    {
        $reference = $this->database()->getReference('official_client_configs');
        foreach ((array) ($reference->getValue() ?? []) as $platform => $configs) {
            if (array_key_exists($id, (array) $configs)) {
                $reference->getChild("{$platform}/{$id}")->remove();
            }
        }
    }

    private function database(): Database
    {
        $url = (string) config('firebase.database_url', '');
        abort_if($url === '', 503, 'Firebase database URL is not configured.');

        return (new Factory)
            ->withServiceAccount((string) config('firebase.credentials'))
            ->withDatabaseUri($url)
            ->createDatabase();
    }
}
