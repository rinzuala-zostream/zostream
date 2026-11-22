<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class NetworkDiagnosticController extends Controller
{
    public function show(Request $request)
    {
        $id = (string) Str::uuid();
        $this->record($request, $id, 'page_opened');

        return response()->view('network-check', [
            'id' => $id,
            // Relative signatures also work behind TLS-terminating proxies.
            'probeUrl' => URL::temporarySignedRoute('network-check.probe', now()->addMinutes(30), ['id' => $id], absolute: false),
            'reportUrl' => URL::temporarySignedRoute('network-check.report', now()->addMinutes(30), ['id' => $id], absolute: false),
        ])->header('Cache-Control', 'no-store, private')
            ->header('Referrer-Policy', 'no-referrer')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public function probe(Request $request, string $id)
    {
        $this->record($request, $id, 'probe_reached');

        return response()->json(['ok' => true, 'reference' => $id])
            ->header('Cache-Control', 'no-store, private');
    }

    public function report(Request $request, string $id)
    {
        $data = $request->validate([
            'online' => 'required|boolean',
            'connection_type' => 'nullable|string|max:32',
            'effective_type' => 'nullable|string|max:32',
            'navigation_ms' => 'nullable|numeric|min:0|max:3600000',
            'tests' => 'required|array|size:3',
            'tests.*' => 'required|array:ok,status,duration_ms,error',
            'tests.*.ok' => 'required|boolean',
            'tests.*.status' => 'nullable|integer|min:0|max:599',
            'tests.*.duration_ms' => 'required|numeric|min:0|max:60000',
            'tests.*.error' => 'nullable|in:timeout,network_error,unexpected_response',
        ]);

        $this->record($request, $id, 'browser_report', ['client_reported' => $data]);

        return response()->json(['saved' => true, 'reference' => $id])
            ->header('Cache-Control', 'no-store, private');
    }

    private function record(Request $request, string $id, string $event, array $extra = []): void
    {
        Log::channel('network_diagnostics')->info($event, array_merge([
            'reference' => $id,
            'peer_ip' => $request->server('REMOTE_ADDR'),
            'request_ip' => $request->ip(),
            // Headers are evidence only; never use them for access control.
            'cf_connecting_ip_header' => Str::limit((string) $request->header('CF-Connecting-IP'), 64, ''),
            'cf_ray_header' => Str::limit((string) $request->header('CF-Ray'), 100, ''),
            'user_agent' => Str::limit((string) $request->userAgent(), 512, ''),
        ], $extra));
    }
}
