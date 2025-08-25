<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class HlsFolderController extends Controller
{
    public function check(Request $request)
    {
        $raw = (string) $request->query('url', '');
        $force = (bool) $request->boolean('force', false);

        if ($raw === '') {
            return $this->error('Missing "url" query parameter.', 422);
        }

        // 1) Resolve MPD URL:
        //    - If it's a real http(s) URL -> use directly
        //    - Else -> attempt to decrypt and expect a valid URL
        if ($this->isHttpUrl($raw)) {
            $mpdUrl = $raw;
            $source = 'plaintext';
        } else {
            [$ok, $dec, $err] = $this->decryptMpdUrl($raw);
            if (!$ok) {
                return $this->error($err ?: 'Failed to resolve MPD URL from "url".', 422);
            }
            $mpdUrl = $dec;
            $source = 'decrypted';
        }

        // 2) Derive relative directory from URL path
        $path = parse_url($mpdUrl, PHP_URL_PATH);
        if (!$path) {
            return $this->error('Could not parse URL path.');
        }
        $dirPathEncoded = dirname($path);
        $relativeDir = trim(urldecode($dirPathEncoded), '/');

        // Safety
        if (Str::contains($relativeDir, ['..', "\0"])) {
            return $this->error('Unsafe path.');
        }

        // 3) Resolve HLS root and absolute output path
        $hlsRoot = rtrim(config('streaming.hls_root', public_path('hls')), DIRECTORY_SEPARATOR);
        $fullPath = $hlsRoot . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativeDir);

        // 4) Ensure folder exists
        if (!is_dir($fullPath)) {
            if (!@mkdir($fullPath, 0755, true) && !is_dir($fullPath)) {
                return $this->error('Failed to create HLS output directory: ' . $fullPath, 500);
            }
        }

        // 5) Build/generate if needed
        $masterPath = rtrim($fullPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'master.m3u8';
        $needsBuild = $force || !is_file($masterPath);

        $stdout = '';
        $stderr = '';
        if ($needsBuild) {
            [$ok, $stdout, $stderr] = $this->generateHlsFromMpd($mpdUrl, $fullPath);
        }

        // 6) Finalize
        $masterExists = is_file($masterPath);
        $encodedPath = implode('/', array_map('rawurlencode', explode('/', $relativeDir)));
        $publicUrl   = url('hls/' . $encodedPath . '/master.m3u8');

        return response()->json([
            'status'  => ($masterExists ? 'success' : 'error'),
            'message' => ($masterExists ? ($needsBuild ? 'HLS generated.' : 'Folder exists.') : 'HLS generation failed.'),
            'data'    => [
                'source'        => $source,            // plaintext | decrypted
                'requested_raw' => $raw,
                'resolved_mpd'  => $mpdUrl,
                'removed'       => $relativeDir,
                'full_path'     => $fullPath,
                'exists'        => true,
                'generated'     => (bool)$needsBuild,
                'master_m3u8'   => $masterExists ? $masterPath : null,
                'stream_url'    => $masterExists ? $publicUrl : null,
                'log'           => [
                    'stdout' => $stdout,
                    'stderr' => $stderr,
                ],
            ],
        ], $masterExists ? 200 : 500);
    }

    private function isHttpUrl(string $s): bool
    {
        if ($s === '') return false;
        if (!filter_var($s, FILTER_VALIDATE_URL)) return false;
        $scheme = parse_url($s, PHP_URL_SCHEME);
        return in_array($scheme, ['http', 'https'], true);
    }

    /**
     * Decrypt an encrypted token into a URL.
     * Input format: base64( 16-byte IV || cipherText ), AES-256-CBC
     * Key = sha256($shaKey, raw=true)
     *
     * @return array [ok(bool), url(string|null), error(string|null)]
     */
    private function decryptMpdUrl(string $encrypted): array
    {
        // Move/override this via .env: STREAMING_SHA_KEY=...
        $shaKey = config('streaming.sha_key', env('STREAMING_SHA_KEY', 'd4c6198dabafb243b0d043a3c33a9fe171f81605158c267c7dfe5f66df29559a'));

        $data = base64_decode($encrypted, true);
        if ($data === false || strlen($data) < 17) {
            return [false, null, 'Invalid base64 or data too short.'];
        }

        $iv         = substr($data, 0, 16);
        $cipherText = substr($data, 16);
        $key        = hash('sha256', $shaKey, true);

        $plain = openssl_decrypt($cipherText, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        if (!is_string($plain) || $plain === '') {
            return [false, null, 'Decryption failed.'];
        }
        if (!$this->isHttpUrl($plain)) {
            return [false, null, 'Decrypted value is not a valid URL.'];
        }

        return [true, $plain, null];
    }

    /**
     * Call the Node mpd-to-hls script safely (no shell).
     * @return array [ok(bool), stdout(string), stderr(string)]
     */
    private function generateHlsFromMpd(string $mpdUrl, string $outDir): array
    {
        $nodeBin = config('streaming.mpd2hls_node', env('MPD2HLS_NODE', '/usr/bin/node'));
        $script  = config('streaming.mpd2hls_script', env('MPD2HLS_SCRIPT', base_path('scripts/mpd-to-hls.js')));

        if (!is_file($script)) {
            return [false, '', "Script not found at: {$script}"];
        }

        $proc = new Process([$nodeBin, $script, $mpdUrl, $outDir]);
        $proc->setTimeout(300);     // 5 min
        $proc->setIdleTimeout(120); // optional

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
