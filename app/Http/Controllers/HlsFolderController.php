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
        $inputUrl = trim((string) $request->query('url', ''));

        if ($inputUrl === '') {
            return $this->error('Missing "url" query parameter.', 422);
        }

        // If spaces slipped in (because query params were decoded), re-encode them
        // This also keeps other existing % encodes intact.
        if (strpos($inputUrl, ' ') !== false) {
            $inputUrl = str_replace(' ', '%20', $inputUrl);
        }

        // Basic scheme/host validation (more tolerant than FILTER_VALIDATE_URL in this case)
        $parts = @parse_url($inputUrl);
        if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
            return $this->error('Invalid URL.');
        }

        // Extract the path (e.g. /Normal/i1%20Thrift%20Shop/manifest.mpd)
        $path = $parts['path'] ?? '';
        if ($path === '') {
            return $this->error('Could not parse URL path.');
        }

        // Remove filename => directory path (e.g. /Normal/i1%20Thrift%20Shop)
        $dirPathEncoded = dirname($path);

        // Normalize to a relative path and decode %20 -> space
        $relativeDir = trim(urldecode($dirPathEncoded), '/'); // "Normal/i1 Thrift Shop"

        // Security: block traversal
        if (\Illuminate\Support\Str::contains($relativeDir, ['..', "\0"])) {
            return $this->error('Unsafe path.');
        }

        // HLS root (configurable via config/streaming.php or .env HLS_ROOT)
        $hlsRoot = rtrim(config('streaming.hls_root', public_path('hls')), DIRECTORY_SEPARATOR);

        // Build absolute path
        $fullPath = $hlsRoot . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativeDir);

        $exists = is_dir($fullPath);

        return response()->json([
            'status' => 'success',
            'message' => $exists ? 'Folder exists.' : 'Folder does not exist.',
            'data' => [
                'requested_url' => $inputUrl,     // normalized (spaces -> %20 if needed)
                'removed' => $relativeDir,  // e.g. "Normal/i1 Thrift Shop"
                'hls_root' => $hlsRoot,
                'full_path' => $fullPath,
                'exists' => $exists,
            ],
        ]);
    }


    private function error(string $message, int $status = 400)
    {
        return response()->json([
            'status' => 'error',
            'message' => $message,
        ], $status);
    }
}
