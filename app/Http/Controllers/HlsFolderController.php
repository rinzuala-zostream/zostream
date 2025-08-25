<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class HlsFolderController extends Controller
{
    public function check(Request $request)
    {
        $raw   = (string) $request->query('url', '');
        $force = (bool) $request->boolean('force', false);

        if ($raw === '') {
            return $this->error('Missing "url" query parameter.', 422);
        }

        // 1) Resolve MPD URL (plaintext or encrypted token)
        [$ok, $mpdUrl, $source, $err] = $this->resolveMpdUrl($raw);
        if (!$ok) {
            return $this->error($err ?: 'Could not resolve MPD URL.', 422);
        }

        // 2) Derive relative directory from URL path (drops the last component: .../X/manifest.mpd -> X)
        $path = parse_url($mpdUrl, PHP_URL_PATH);
        if (!$path) {
            return $this->error('Could not parse URL path.');
        }
        // dirname("/Normal/i1 Thrift Shop/manifest.mpd") => "/Normal/i1 Thrift Shop"
        $dirPathEncoded = dirname($path);
        $relativeDir    = trim(urldecode($dirPathEncoded), '/');

        // Safety guards
        if ($relativeDir === '' || Str::contains($relativeDir, ['..', "\0"])) {
            return $this->error('Unsafe or empty path derived.');
        }

        // (Optional) Only allow specific hostnames (configure in config/streaming.php)
        $allowHosts = (array) config('streaming.host_allowlist', []); // e.g. ['cdn.zostream.in']
        if (!empty($allowHosts)) {
            $host = parse_url($mpdUrl, PHP_URL_HOST);
            if (!$host || !in_array($host, $allowHosts, true)) {
                return $this->error('MPD host not allowed.');
            }
        }

        // 3) Resolve HLS root and absolute output path
        $hlsRoot  = rtrim((string) config('streaming.hls_root', public_path('hls')), DIRECTORY_SEPARATOR);
        $safeSub  = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativeDir);
        $fullPath = $hlsRoot . DIRECTORY_SEPARATOR . $safeSub;

        // 4) Ensure folder exists
        if (!is_dir($fullPath)) {
            if (!@mkdir($fullPath, 0755, true) && !is_dir($fullPath)) {
                return $this->error('Failed to create HLS output directory: ' . $fullPath, 500);
            }
        }

        // 5) Build/generate if needed
        $masterPath  = rtrim($fullPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'master.m3u8';
        $needsBuild  = $force || !is_file($masterPath);
        $stdout = '';
        $stderr = '';

        if ($needsBuild) {
            [$genOk, $stdout, $stderr] = $this->generateHlsFromMpd($mpdUrl, $fullPath);
            // re-evaluate after attempted generation
        }

        $masterExists = is_file($masterPath);

        // 6) Public stream URL (URL-encode every path segment)
        $encodedPath = implode('/', array_map('rawurlencode', explode('/', $relativeDir)));
        $publicBase  = rtrim((string) config('streaming.hls_public_base', url('hls')), '/'); // override if needed
        $publicUrl   = $masterExists ? $publicBase . '/' . $encodedPath . '/master.m3u8' : null;

        return response()->json([
            'status'  => $masterExists ? 'success' : 'error',
            'message' => $masterExists ? ($needsBuild ? 'HLS generated.' : 'Folder exists.') : 'HLS generation failed.',
            'data'    => [
                'source'        => $source,            // plaintext | decrypted
                'requested_raw' => $raw,
                'resolved_mpd'  => $mpdUrl,
                'removed'       => $relativeDir,
                'full_path'     => $fullPath,
                'exists'        => is_dir($fullPath),
                'generated'     => (bool) $needsBuild,
                'master_m3u8'   => $masterExists ? $masterPath : null,
                'stream_url'    => $publicUrl,
                'log'           => [
                    'stdout' => $stdout,
                    'stderr' => $stderr,
                ],
            ],
        ], $masterExists ? 200 : 500);
    }

    /**
     * Resolve the MPD URL from plaintext URL or encrypted token (AES-256-CBC).
     * Returns [ok(bool), url(string|null), source('plaintext'|'decrypted'|null), error(string|null)]
     */
    private function resolveMpdUrl(string $raw): array
    {
        $looksHttp = Str::contains($raw, 'http');
        $hasMpd    = Str::contains($raw, '.mpd');

        if ($looksHttp && $hasMpd) {
            $mpd = $this->normalizeMpdUrl($raw);
            if (!$this->isValidMpdUrl($mpd)) {
                return [false, null, null, 'Invalid MPD URL.'];
            }
            return [true, $mpd, 'plaintext', null];
        }

        // Try decrypting url-safe or standard base64 ciphertext
        $cipherB64 = $this->normalizeBase64($raw);
        $data      = base64_decode($cipherB64, true);
        if ($data === false || strlen($data) < 17) {
            return [false, null, null, 'Invalid ciphertext: base64 decode failed.'];
        }

        $iv         = substr($data, 0, 16);
        $cipherText = substr($data, 16);

        // Your static SHA seed -> SHA256 -> 32-byte key
        $shaKey        = (string) config('streaming.decrypt_seed', 'd4c6198dabafb243b0d043a3c33a9fe171f81605158c267c7dfe5f66df29559a');
        $decryptionKey = hash('sha256', $shaKey, true);

        $plain = openssl_decrypt($cipherText, 'aes-256-cbc', $decryptionKey, OPENSSL_RAW_DATA, $iv);
        if ($plain === false) {
            return [false, null, null, 'Decryption failed.'];
        }

        $mpd = $this->normalizeMpdUrl(trim(str_replace(["\n", "\r"], '', $plain)));
        if (!$this->isValidMpdUrl($mpd)) {
            return [false, null, null, 'Decrypted value is not a valid MPD URL.'];
        }

        return [true, $mpd, 'decrypted', null];
    }

    /**
     * Accept any .../*.mpd (manifest.mpd, stream.mpd, etc.), keep query/hash allowed.
     */
    private function isValidMpdUrl(string $url): bool
    {
        if (!preg_match('~^https?://~i', $url)) return false;
        $path = parse_url($url, PHP_URL_PATH);
        return is_string($path) && str_ends_with(strtolower($path), '.mpd');
    }

    /**
     * Normalize MPD URL by removing extra spaces and ensuring it's a proper URL.
     */
    private function normalizeMpdUrl(string $u): string
    {
        $u = trim($u);
        // If someone pasted spaces in scheme/host, compress them
        $u = preg_replace('~\s+~', '', $u);
        return $u;
    }

    /**
     * Convert URL-safe Base64 to standard and add missing padding.
     */
    private function normalizeBase64(string $s): string
    {
        $s = str_replace([' ', '-', '_'], ['+', '+', '/'], $s);
        $pad = strlen($s) % 4;
        if ($pad > 0) $s .= str_repeat('=', 4 - $pad);
        return $s;
    }

    /**
     * Call the Node mpd-to-hls script safely (no shell).
     * @return array [ok(bool), stdout(string), stderr(string)]
     */
    private function generateHlsFromMpd(string $mpdUrl, string $outDir): array
    {
        $nodeBin = (string) config('streaming.mpd2hls_node', env('MPD2HLS_NODE', '/usr/bin/node'));
        $script  = (string) config('streaming.mpd2hls_script', env('MPD2HLS_SCRIPT', base_path('scripts/mpd-to-hls.js')));

        if (!is_file($script)) {
            return [false, '', "Script not found at: {$script}"];
        }
        if (!is_dir($outDir)) {
            @mkdir($outDir, 0755, true);
        }

        $proc = new Process([$nodeBin, $script, $mpdUrl, $outDir]);
        $proc->setTimeout((int) config('streaming.mpd2hls_timeout', 300));     // 5 min default
        $proc->setIdleTimeout((int) config('streaming.mpd2hls_idle', 120));    // optional

        try {
            $proc->run();
            return [$proc->isSuccessful(), $proc->getOutput(), $proc->getErrorOutput()];
        } catch (\Throwable $e) {
            return [false, '', 'Exception: ' . $e->getMessage()];
        }
    }

    private function error(string $message, int $status = 400)
    {
        return response()->json(['status' => 'error', 'message' => $message], $status);
    }
}
