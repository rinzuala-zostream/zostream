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
        if (Str::contains($raw, 'http') && Str::contains($raw, 'mpd')) {
            // Valid MPD URL with http/https
            $mpdUrl = $raw;
            $source = 'plaintext';
        } else {
            // Not a valid URL → try decrypt
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
        $publicUrl = url('hls/' . $encodedPath . '/master.m3u8');

        return response()->json([
            'status' => ($masterExists ? 'success' : 'error'),
            'message' => ($masterExists ? ($needsBuild ? 'HLS generated.' : 'Folder exists.') : 'HLS generation failed.'),
            'data' => [
                'source' => $source,            // plaintext | decrypted
                'requested_raw' => $raw,
                'resolved_mpd' => $mpdUrl,
                'removed' => $relativeDir,
                'full_path' => $fullPath,
                'exists' => true,
                'generated' => (bool) $needsBuild,
                'master_m3u8' => $masterExists ? $masterPath : null,
                'stream_url' => $masterExists ? $publicUrl : null,
                'log' => [
                    'stdout' => $stdout,
                    'stderr' => $stderr,
                ],
            ],
        ], $masterExists ? 200 : 500);
    }

    private function decryptMpdUrl(string $raw): array
    {
        // 1) Your 256-bit key as hex (64 chars)
        $keyHex = 'd4c6198dabafb243b0d043a3c33a9fe171f81605158c267c7dfe5f66df29559a';

        // 2) Convert hex → binary key (DO NOT hash the hex string again)
        if (strlen($keyHex) !== 64 || !ctype_xdigit($keyHex)) {
            return [false, null, 'Key is not a 64-character hex string.'];
        }
        $key = hex2bin($keyHex); // 32 bytes

        // 3) Handle URL-safe Base64 and decode in strict mode
        $rawNorm = strtr($raw, ['-' => '+', '_' => '/']);
        $padLen = (4 - (strlen($rawNorm) % 4)) % 4;
        if ($padLen)
            $rawNorm .= str_repeat('=', $padLen);

        $data = base64_decode($rawNorm, true);
        if ($data === false) {
            return [false, null, 'Invalid base64 input.'];
        }

        // 4) Split IV (first 16 bytes) + ciphertext
        if (strlen($data) < 17) {
            return [false, null, 'Ciphertext too short (missing IV).'];
        }
        $iv = substr($data, 0, 16);
        $cipherText = substr($data, 16);

        // 5) Decrypt (PKCS#7 padding is default)
        $plain = openssl_decrypt(
            $cipherText,
            'aes-256-cbc',
            $key,
            OPENSSL_RAW_DATA,
            $iv
        );

        if ($plain === false) {
            return [false, null, 'OpenSSL decryption failed (wrong key/IV/mode?).'];
        }

        $result = str_replace(["\n", "\r"], '', $plain);
        return [true, $result, null];
    }

    /**
     * Call the Node mpd-to-hls script safely (no shell).
     * @return array [ok(bool), stdout(string), stderr(string)]
     */
    private function generateHlsFromMpd(string $mpdUrl, string $outDir): array
    {
        $nodeBin = config('streaming.mpd2hls_node', env('MPD2HLS_NODE', '/usr/bin/node'));
        $script = config('streaming.mpd2hls_script', env('MPD2HLS_SCRIPT', base_path('scripts/mpd-to-hls.js')));

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
