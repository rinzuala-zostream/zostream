<?php

namespace App\Http\Controllers\Api\V4;

use App\Http\Controllers\Controller;
use App\Support\Api\V4Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class DesktopReleaseUploadController extends Controller
{
    private const EXTENSIONS = [
        'macos_update' => ['dmg', 'pkg', 'zip'],
        'windows_update' => ['exe', 'msi', 'msix', 'msixbundle', 'appx', 'appxbundle', 'zip'],
    ];

    public function __invoke(Request $request, string $platform)
    {
        if (! array_key_exists($platform, self::EXTENSIONS)) {
            return V4Response::error('INVALID_RELEASE_PLATFORM', 'Installer uploads are available for Mac and Windows only.', 422);
        }

        $file = $request->file('file');
        if ($file && ! $file->isValid()) {
            if (in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
                return V4Response::error(
                    'RELEASE_UPLOAD_LIMIT_EXCEEDED',
                    'The server rejected this installer because it exceeds the configured upload limit. Set PHP upload_max_filesize to at least 512M and post_max_size to at least 520M.',
                    413,
                );
            }

            return V4Response::error('RELEASE_UPLOAD_FAILED', 'The installer did not reach the server intact. Check the connection and server upload configuration, then try again.', 422);
        }

        $validator = Validator::make($request->all(), [
            // Installer MIME types vary across OS/browser combinations. Restrict
            // the extension and size, and serve from object storage as a download.
            'file' => ['required', 'file', 'min:1', 'max:524288', 'extensions:'.implode(',', self::EXTENSIONS[$platform])],
        ]);
        if ($validator->fails()) {
            return V4Response::error('INVALID_RELEASE_FILE', 'Choose a supported installer file, up to 512 MB.', 422, $validator->errors());
        }

        try {
            $publicUrl = config('filesystems.disks.r2.url');
            if (! is_string($publicUrl) || ! filter_var($publicUrl, FILTER_VALIDATE_URL) || parse_url($publicUrl, PHP_URL_SCHEME) !== 'https') {
                throw new \RuntimeException('A public HTTPS R2 URL is required for release downloads.');
            }
            $file = $request->file('file');
            $extension = strtolower($file->getClientOriginalExtension());
            $name = substr(Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)), 0, 100);
            $filename = ($name !== '' ? $name : 'zostream').'.'.$extension;
            $directory = 'app-releases/'.str_replace('_update', '', $platform).'/'.Str::uuid();
            $path = $directory.'/'.$filename;
            $disk = Storage::disk('r2');
            $url = $disk->url($path);
            if (! filter_var($url, FILTER_VALIDATE_URL) || parse_url($url, PHP_URL_SCHEME) !== 'https') {
                throw new \RuntimeException('Release storage did not produce a public HTTPS URL.');
            }
            $stored = $disk->putFileAs($directory, $file, $filename, [
                'ContentType' => 'application/octet-stream',
                'ContentDisposition' => 'attachment; filename="'.$filename.'"',
                'CacheControl' => 'public, max-age=31536000, immutable',
            ]);
            if ($stored === false) {
                throw new \RuntimeException('Release file could not be stored.');
            }

            // Uploading prepares a URL; the existing release save action is the
            // only operation that publishes that URL to the update configuration.
            return V4Response::success([
                'platform' => $platform,
                'url' => $url,
                'filename' => $filename,
                'size' => $file->getSize(),
            ], 'Installer uploaded. Save the release to publish its download URL.', status: 201);
        } catch (\Throwable $error) {
            Log::error('Desktop release upload failed', ['platform' => $platform, 'exception' => $error]);

            return V4Response::error('RELEASE_UPLOAD_FAILED', 'Installer upload failed. Please try again.', 500);
        }
    }
}
