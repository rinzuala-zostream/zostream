<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

class HlsFolderController extends Controller
{
    /**
     * GET /api/hls/check-folder?url=<mpd_or_m3u8_url>
     */
    public function check(Request $request)
    {
        $inputUrl = $request->query('url');

        if (empty($inputUrl)) {
            return $this->error('Missing "url" query parameter.', 422);
        }

        // Validate URL
        if (!filter_var($inputUrl, FILTER_VALIDATE_URL)) {
            return $this->error('Invalid URL.');
        }

        // Extract the path part of the URL (e.g. /Normal/i1%20Thrift%20Shop/manifest.mpd)
        $path = parse_url($inputUrl, PHP_URL_PATH);
        if (!$path) {
            return $this->error('Could not parse URL path.');
        }

        // Remove filename => get directory path (e.g. /Normal/i1%20Thrift%20Shop)
        $dirPathEncoded = dirname($path);

        // Normalize: trim leading/trailing slashes and decode %20 -> space
        $relativeDir = trim(urldecode($dirPathEncoded), '/'); // "Normal/i1 Thrift Shop"

        // Basic security: prevent traversal like ../../etc
        if (Str::contains($relativeDir, ['..', "\0"])) {
            return $this->error('Unsafe path.');
        }

        // Resolve HLS root (configurable)
        $hlsRoot = rtrim(config('streaming.hls_root', public_path('hls')), DIRECTORY_SEPARATOR);

        // Build full path to check
        $fullPath = $hlsRoot . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativeDir);

        $exists = is_dir($fullPath);

        return response()->json([
            'status'     => 'success',
            'message'    => $exists ? 'Folder exists.' : 'Folder does not exist.',
            'data'       => [
                'requested_url' => $inputUrl,
                'removed'       => $relativeDir,     // e.g. "Normal/i1 Thrift Shop"
                'hls_root'      => $hlsRoot,
                'full_path'     => $fullPath,
                'exists'        => $exists,
            ],
        ], 200);
    }

    private function error(string $message, int $status = 400)
    {
        return response()->json([
            'status'  => 'error',
            'message' => $message,
        ], $status);
    }
}
