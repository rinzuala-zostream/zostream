<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class WebpImageUploader
{
    public const MAX_BYTES = 500 * 1024;

    public function upload(
        UploadedFile $file,
        string $directory,
        int $maxBytes = self::MAX_BYTES
    ): string
    {
        $contents = $this->webpContents($file, $maxBytes);
        $path = trim($directory, '/').'/'.Str::uuid().'.webp';

        Storage::disk('r2')->put($path, $contents, [
            'visibility' => 'public',
            'ContentType' => 'image/webp',
        ]);

        return rtrim((string) config('filesystems.disks.r2.url'), '/').'/'.$this->encodePath($path);
    }

    public function webpContents(UploadedFile $file, int $maxBytes = self::MAX_BYTES): string
    {
        if ($maxBytes < 1) {
            throw new RuntimeException('The maximum image size must be greater than zero.');
        }

        $sourcePath = $file->getRealPath();

        if ($sourcePath === false || ! is_file($sourcePath)) {
            throw new RuntimeException('Unable to read the uploaded image.');
        }

        if ($file->getMimeType() === 'image/webp' && filesize($sourcePath) <= $maxBytes) {
            $contents = file_get_contents($sourcePath);

            if ($contents !== false) {
                return $contents;
            }
        }

        if (! function_exists('imagecreatefromstring') || ! function_exists('imagewebp')) {
            throw new RuntimeException('The PHP GD WebP extension is required to process images.');
        }

        $source = file_get_contents($sourcePath);
        $image = $source === false ? false : @imagecreatefromstring($source);

        if ($image === false) {
            throw new RuntimeException('The uploaded image format could not be processed.');
        }

        imagepalettetotruecolor($image);
        imagealphablending($image, false);
        imagesavealpha($image, true);

        for ($resizeAttempt = 0; $resizeAttempt < 12; $resizeAttempt++) {
            foreach ([85, 75, 65, 55, 45, 35] as $quality) {
                $encoded = $this->encodeWebp($image, $quality);

                if (strlen($encoded) <= $maxBytes) {
                    return $encoded;
                }
            }

            $width = imagesx($image);
            $height = imagesy($image);

            if ($width <= 1 && $height <= 1) {
                break;
            }

            $ratio = min(0.85, sqrt($maxBytes / strlen($encoded)) * 0.9);
            $newWidth = max(1, (int) floor($width * $ratio));
            $newHeight = max(1, (int) floor($height * $ratio));
            $resized = imagecreatetruecolor($newWidth, $newHeight);

            if ($resized === false) {
                throw new RuntimeException('Unable to resize the uploaded image.');
            }

            imagealphablending($resized, false);
            imagesavealpha($resized, true);

            if (! imagecopyresampled(
                $resized,
                $image,
                0,
                0,
                0,
                0,
                $newWidth,
                $newHeight,
                $width,
                $height
            )) {
                throw new RuntimeException('Unable to resize the uploaded image.');
            }

            $image = $resized;
        }

        throw new RuntimeException("Unable to reduce the image below {$maxBytes} bytes.");
    }

    private function encodeWebp(\GdImage $image, int $quality): string
    {
        ob_start();
        $encoded = imagewebp($image, null, $quality);
        $contents = ob_get_clean();

        if (! $encoded || $contents === false) {
            throw new RuntimeException('Unable to encode the uploaded image as WebP.');
        }

        return $contents;
    }

    private function encodePath(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }
}
