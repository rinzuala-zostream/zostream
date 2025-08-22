<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class HlsFolderController extends Controller
{
    public function check(Request $request)
    {
        $inputUrl = (string)$request->query('url', '');
        $force    = (bool)$request->boolean('force', false);

        if ($inputUrl === '') {
            return $this->error('Missing "url" query parameter.', 422);
        }

        // 1) Derive relative dir from the URL path (e.g. "Normal/i1 Thrift Shop")
        $path = parse_url($inputUrl, PHP_URL_PATH);
        if (!$path) {
            return $this->error('Could not parse URL path.');
        }
        $dirPathEncoded = dirname($path);
        $relativeDir    = trim(urldecode($dirPathEncoded), '/');

        // Safety against traversal
        if (Str::contains($relativeDir, ['..', "\0"])) {
            return $this->error('Unsafe path.');
        }

        // 2) Resolve HLS root and absolute output path
        $hlsRoot  = rtrim(config('streaming.hls_root', public_path('hls')), DIRECTORY_SEPARATOR);
        $fullPath = $hlsRoot . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativeDir);

        // 3) Ensure folder exists (create recursively)
        if (!is_dir($fullPath)) {
            if (!@mkdir($fullPath, 0755, true) && !is_dir($fullPath)) {
                return $this->error('Failed to create HLS output directory: ' . $fullPath, 500);
            }
        }

        // 4) Decide whether we need to (re)generate
        $masterPath   = rtrim($fullPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'master.m3u8';
        $needsBuild   = $force || !is_file($masterPath);

        $stdout = '';
        $stderr = '';
        $ok     = true;

        if ($needsBuild) {
            // Run the Node generator (no shell; args are separated so spaces in paths are safe)
            [$ok, $stdout, $stderr] = $this->generateHlsFromMpd($inputUrl, $fullPath);
        }

        // 5) Check success signal
        $masterExists = is_file($masterPath);

        // Build a URL-safe public path for the stream URL
        $encodedPath = implode('/', array_map('rawurlencode', explode('/', $relativeDir)));
        $publicUrl   = url('hls/' . $encodedPath . '/master.m3u8');

        return response()->json([
            'status'  => ($masterExists ? 'success' : 'error'),
            'message' => ($masterExists ? ($needsBuild ? 'HLS generated.' : 'Folder exists.') : 'HLS generation failed.'),
            'data'    => [
                'requested_url' => $inputUrl,
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
