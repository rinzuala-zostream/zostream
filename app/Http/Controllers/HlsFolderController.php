<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class HlsFolderController extends Controller
{
    /**
     * GET /api/hls/check-folder?url=<mpd_url>&create=1
     *
     * Examples:
     *   /api/hls/check-folder?url=https://cdn.zostream.in/Normal/Show/manifest.mpd
     *   /api/hls/check-folder?url=https://cdn.zostream.in/Normal/i1%20Thrift%20Shop/manifest.mpd&create=1
     */
    public function check(Request $request)
    {
        $mpdUrl = $request->query('url') ?? $request->input('url');
        if (!$mpdUrl) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Missing "url" parameter.',
            ], 422);
        }

        // Basic validation
        if (!filter_var($mpdUrl, FILTER_VALIDATE_URL)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Invalid URL.',
            ], 422);
        }

        // Extract just the path portion of the URL
        $urlPath = parse_url($mpdUrl, PHP_URL_PATH) ?? '';

        // Normalize and decode any %20 etc.
        $urlPath = urldecode($urlPath);

        // Remove trailing slashes, just in case
        $urlPath = rtrim($urlPath, '/');

        if ($urlPath === '') {
            return response()->json([
                'status'  => 'error',
                'message' => 'URL does not contain a path.',
            ], 422);
        }

        // Ensure it ends with .mpd (ignore query strings)
        $filename = basename($urlPath);
        if (!Str::endsWith(Str::lower($filename), '.mpd')) {
            return response()->json([
                'status'  => 'error',
                'message' => 'URL does not point to an MPD file.',
                'details' => ['filename' => $filename],
            ], 422);
        }

        // Remove the filename to keep only directory structure
        $relativeFolder = trim(dirname($urlPath), '/'); // e.g. "Normal/Show" or "Normal/i1 Thrift Shop"
        // If dirname returns '.', we want empty (root under hls/)
        if ($relativeFolder === '.' || $relativeFolder === DIRECTORY_SEPARATOR) {
            $relativeFolder = '';
        }

        // Where your HLS root lives (public/hls by default)
        $hlsRoot = public_path('hls');

        // Full local folder path
        $localFolder = $relativeFolder
            ? $hlsRoot . DIRECTORY_SEPARATOR . $relativeFolder
            : $hlsRoot;

        // Check existence
        $exists = File::isDirectory($localFolder);

        // Optionally create if requested
        $created = false;
        if (!$exists && $request->boolean('create')) {
            $created = File::makeDirectory($localFolder, 0755, true);
            $exists  = $created && File::isDirectory($localFolder);
        }

        // Build a couple of convenience values
        $publicFolderUrl = url('hls' . ($relativeFolder ? '/' . str_replace(DIRECTORY_SEPARATOR, '/', $relativeFolder) : ''));

        return response()->json([
            'status' => 'success',
            'data' => [
                'input_url'        => $mpdUrl,
                'url_path'         => $urlPath,                 // e.g. Normal/Show/manifest.mpd
                'filename'         => $filename,                // e.g. manifest.mpd
                'relative_folder'  => $relativeFolder,          // e.g. Normal/Show
                'hls_root'         => $hlsRoot,                 // e.g. /var/www/html/public/hls
                'local_folder'     => $localFolder,             // e.g. /var/www/html/public/hls/Normal/Show
                'public_folder_url'=> $publicFolderUrl,         // e.g. https://site/hls/Normal/Show
                'exists'           => $exists,
                'created_now'      => $created,
            ],
        ]);
    }
}
