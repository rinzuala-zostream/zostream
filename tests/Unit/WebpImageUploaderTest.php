<?php

namespace Tests\Unit;

use App\Support\WebpImageUploader;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WebpImageUploaderTest extends TestCase
{
    public function test_it_converts_a_non_webp_image_and_uploads_it_under_500_kib(): void
    {
        Storage::fake('r2');
        config(['filesystems.disks.r2.url' => 'https://images.example.test']);

        $uploader = new WebpImageUploader;
        $url = $uploader->upload(
            UploadedFile::fake()->image('cover.jpg', 1600, 900),
            'thumbnail/movie/cover'
        );

        $path = str_replace('https://images.example.test/', '', $url);
        Storage::disk('r2')->assertExists($path);

        $contents = Storage::disk('r2')->get($path);

        $this->assertStringEndsWith('.webp', $path);
        $this->assertLessThanOrEqual(WebpImageUploader::MAX_BYTES, strlen($contents));
        $this->assertSame('RIFF', substr($contents, 0, 4));
        $this->assertSame('WEBP', substr($contents, 8, 4));
    }

    public function test_it_does_not_reencode_an_already_small_webp_image(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'webp-test-');
        $image = imagecreatetruecolor(20, 20);
        imagewebp($image, $path, 80);

        try {
            $original = file_get_contents($path);
            $file = new UploadedFile($path, 'poster.webp', 'image/webp', null, true);

            $this->assertSame($original, (new WebpImageUploader)->webpContents($file));
        } finally {
            @unlink($path);
        }
    }

    public function test_it_reencodes_an_oversized_webp_below_500_kib(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'webp-test-');
        $image = imagecreatetruecolor(1400, 1400);

        for ($y = 0; $y < 1400; $y += 4) {
            for ($x = 0; $x < 1400; $x += 4) {
                $color = (($x * 73856093) ^ ($y * 19349663)) & 0xFFFFFF;
                imagefilledrectangle($image, $x, $y, $x + 3, $y + 3, $color);
            }
        }

        imagewebp($image, $path, 100);

        try {
            $this->assertGreaterThan(WebpImageUploader::MAX_BYTES, filesize($path));

            $file = new UploadedFile($path, 'large-poster.webp', 'image/webp', null, true);
            $contents = (new WebpImageUploader)->webpContents($file);

            $this->assertLessThanOrEqual(WebpImageUploader::MAX_BYTES, strlen($contents));
            $this->assertSame('WEBP', substr($contents, 8, 4));

            $episodeLimit = 100 * 1024;
            $episodeThumbnail = (new WebpImageUploader)->webpContents($file, $episodeLimit);

            $this->assertLessThanOrEqual($episodeLimit, strlen($episodeThumbnail));
            $this->assertSame('WEBP', substr($episodeThumbnail, 8, 4));
        } finally {
            @unlink($path);
        }
    }
}
