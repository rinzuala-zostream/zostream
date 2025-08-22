<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

class HlsFolderController extends Controller
{
    public function check(Request $request)
    {
        $inputUrl = $request->query('url');

        if (empty($inputUrl)) {
            return $this->error('Missing "url" query parameter.', 422);
        }

        // Parse URL path (keeps whatever was sent)
        $path = parse_url($inputUrl, PHP_URL_PATH);

        if (!$path) {
            return $this->error('Could not parse URL path.');
        }

        // Remove the filename => directory path (e.g. /Normal/i1%20Thrift%20Shop)
        $dirPathEncoded = dirname($path);

        // Normalize: trim slashes and decode (%20 → space)
        $relativeDir = trim(urldecode($dirPathEncoded), '/');

        // Security: block traversal
        if (Str::contains($relativeDir, ['..', "\0"])) {
            return $this->error('Unsafe path.');
        }

        // HLS root folder
        $hlsRoot = rtrim(config('streaming.hls_root', public_path('hls')), DIRECTORY_SEPARATOR);

        // Build full path
        $fullPath = $hlsRoot . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativeDir);

        $exists = is_dir($fullPath);

        return response()->json([
            'status'  => 'success',
            'message' => $exists ? 'Folder exists.' : 'Folder does not exist.',
            'data'    => [
                'requested_url' => $inputUrl,
                'removed'       => $relativeDir,  // e.g. Normal/i1 Thrift Shop
                'full_path'     => $fullPath,
                'exists'        => $exists,
            ],
        ]);
    }

    private function error(string $message, int $status = 400)
    {
        return response()->json([
            'status'  => 'error',
            'message' => $message,
        ], $status);
    }
}
