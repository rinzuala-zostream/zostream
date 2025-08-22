<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

class HlsFolderController extends Controller
{
    public function check(Request $request)
    {
        $inputUrl = (string) $request->query('url', '');

        if ($inputUrl === '') {
            return $this->error('Missing "url" query parameter.', 422);
        }

        // Parse the path: e.g. /Normal/i1%20Thrift%20Shop/manifest.mpd
        $path = parse_url($inputUrl, PHP_URL_PATH);
        if (!$path) {
            return $this->error('Could not parse URL path.');
        }

        // Remove filename → /Normal/i1%20Thrift%20Shop
        $dirPathEncoded = dirname($path);

        // Normalize: decode + trim slashes (becomes "Normal/i1 Thrift Shop")
        $relativeDir = trim(urldecode($dirPathEncoded), '/');

        // Security: block traversal
        if (Str::contains($relativeDir, ['..', "\0"])) {
            return $this->error('Unsafe path.');
        }

        // Where HLS lives (configurable)
        $hlsRoot = rtrim(config('streaming.hls_root', public_path('hls')), DIRECTORY_SEPARATOR);

        // Full output directory on disk (spaces OK)
        $fullPath = $hlsRoot . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativeDir);

        // If it exists, just return
        if (is_dir($fullPath)) {
            return response()->json([
                'status'  => 'success',
                'message' => 'Folder exists.',
                'data'    => [
                    'requested_url' => $inputUrl,
                    'removed'       => $relativeDir,
                    'full_path'     => $fullPath,
                    'exists'        => true,
                    'generated'     => false,
                ],
            ]);
        }

        // Ensure parent dirs exist
        if (!is_dir($fullPath) && !@mkdir($fullPath, 0755, true)) {
            return $this->error('Failed to create HLS output directory: ' . $fullPath, 500);
        }

        // Generate HLS playlists via Node script
        [$ok, $stdout, $stderr] = $this->generateHlsFromMpd($inputUrl, $fullPath);

        // Check for master.m3u8 as success signal
        $masterPath = rtrim($fullPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'master.m3u8';
        $masterExists = is_file($masterPath);

        return response()->json([
            'status'  => $ok && $masterExists ? 'success' : 'error',
            'message' => $ok && $masterExists ? 'HLS generated.' : 'HLS generation failed.',
            'data'    => [
                'requested_url' => $inputUrl,
                'removed'       => $relativeDir,
                'full_path'     => $fullPath,
                'exists'        => true,
                'generated'     => $ok,
                'master_m3u8'   => $masterExists ? $masterPath : null,
                'log'           => [
                    'stdout' => $stdout,
                    'stderr' => $stderr,
                ],
            ],
        ], $ok && $masterExists ? 200 : 500);
    }

    /**
     * Call the Node mpd-to-hls script safely (no shell)
     * @return array [ok(bool), stdout(string), stderr(string)]
     */
    private function generateHlsFromMpd(string $mpdUrl, string $outDir): array
    {
        // Path to Node and script — configure via .env
        $nodeBin   = config('streaming.mpd2hls_node', env('MPD2HLS_NODE', '/usr/bin/node'));
        $script    = config('streaming.mpd2hls_script', env('MPD2HLS_SCRIPT', base_path('scripts/mpd-to-hls.js')));

        if (!is_file($script)) {
            return [false, '', "Script not found at: {$script}"];
        }

        $process = new Process([$nodeBin, $script, $mpdUrl, $outDir]);
        $process->setTimeout(300);      // 5 minutes
        $process->setIdleTimeout(120);  // optional

        try {
            $process->run();
            $ok = $process->isSuccessful();
            return [$ok, $process->getOutput(), $process->getErrorOutput()];
        } catch (\Throwable $e) {
            return [false, '', 'Exception: ' . $e->getMessage()];
        }
    }

    private function error(string $message, int $status = 400)
    {
        return response()->json([
            'status'  => 'error',
            'message' => $message,
        ], $status);
    }
}
