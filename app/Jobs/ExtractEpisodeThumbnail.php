<?php

namespace App\Jobs;

use App\Models\New\Episode;
use App\Support\WebpImageUploader;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class ExtractEpisodeThumbnail
{
    use Dispatchable;

    private const MAX_THUMBNAIL_BYTES = 100 * 1024;

    public function __construct(
        private readonly string $episodeId,
        private readonly string $videoUrl,
        private readonly bool $replaceExisting = false
    ) {
    }

    public function handle(WebpImageUploader $imageUploader): void
    {
        $episode = Episode::where('id', $this->episodeId)->first();

        if (!$episode || (!$this->replaceExisting && !empty($episode->thumbnail))) {
            return;
        }

        $thumbnail = $this->extractThumbnailFromMpd($this->videoUrl, $episode->id, $imageUploader);

        if ($thumbnail) {
            $episode->update(['thumbnail' => $thumbnail]);
        }
    }

    private function extractThumbnailFromMpd(
        string $rawUrl,
        string $episodeId,
        WebpImageUploader $imageUploader
    ): ?string
    {
        $outputPath = null;

        try {
            $mpdUrl = $this->resolveMpdUrl($rawUrl);
            $outputDir = storage_path('app/temp/episode-thumbnails');

            if (!is_dir($outputDir) && !@mkdir($outputDir, 0755, true) && !is_dir($outputDir)) {
                throw new \RuntimeException("Unable to create thumbnail directory: {$outputDir}");
            }

            $outputPath = $outputDir . DIRECTORY_SEPARATOR . $episodeId . '.webp';
            $process = new Process([
                'ffmpeg',
                '-y',
                '-ss',
                $this->randomThumbnailSeekTime($mpdUrl),
                '-i',
                str_replace(' ', '%20', $mpdUrl),
                '-frames:v',
                '1',
                '-c:v',
                'libwebp',
                '-quality',
                '82',
                $outputPath,
            ]);

            $process->setTimeout(60);
            $process->run();

            if (!$process->isSuccessful() || !is_file($outputPath) || filesize($outputPath) === 0) {
                throw new \RuntimeException(trim($process->getErrorOutput()) ?: 'ffmpeg thumbnail extraction failed');
            }

            return $imageUploader->upload(
                new UploadedFile($outputPath, basename($outputPath), 'image/webp', null, true),
                'thumbnail/episode',
                self::MAX_THUMBNAIL_BYTES
            );
        } catch (\Throwable $e) {
            Log::warning('Episode thumbnail extraction failed', [
                'episode_id' => $episodeId,
                'url_type' => $this->debugUrlType($rawUrl),
                'url_start' => substr($rawUrl, 0, 80),
                'error' => $e->getMessage(),
            ]);

            return null;
        } finally {
            if ($outputPath && is_file($outputPath)) {
                @unlink($outputPath);
            }
        }
    }

    private function randomThumbnailSeekTime(string $mpdUrl): string
    {
        if (stripos($mpdUrl, '.mpd') === false) {
            return '00:00:03';
        }

        $duration = $this->getMpdDurationSeconds($mpdUrl);

        if ($duration <= 0) {
            return '00:00:03';
        }

        $start = max(1, (int) floor($duration * 0.45));
        $end = max($start, (int) ceil($duration * 0.55));

        if ($duration > 20) {
            $end = min($end, (int) $duration - 5);
        }

        return $this->formatSecondsAsTimestamp(random_int($start, max($start, $end)));
    }

    private function getMpdDurationSeconds(string $mpdUrl): float
    {
        try {
            $response = Http::connectTimeout(1)
                ->timeout(3)
                ->get(str_replace(' ', '%20', $mpdUrl));

            if (!$response->successful()) {
                return 0;
            }

            $xml = @simplexml_load_string($response->body());

            if (!$xml || empty($xml['mediaPresentationDuration'])) {
                return 0;
            }

            return $this->parseIso8601Duration((string) $xml['mediaPresentationDuration']);
        } catch (\Throwable $e) {
            Log::warning('Failed to read MPD duration', [
                'episode_id' => $this->episodeId,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    private function parseIso8601Duration(string $duration): float
    {
        if (!preg_match('/^P(?:(\d+)Y)?(?:(\d+)M)?(?:(\d+)D)?T?(?:(\d+)H)?(?:(\d+)M)?(?:(\d+(?:\.\d+)?)S)?$/', $duration, $matches)) {
            return 0;
        }

        $days = (int) ($matches[3] ?? 0);
        $hours = (int) ($matches[4] ?? 0);
        $minutes = (int) ($matches[5] ?? 0);
        $seconds = (float) ($matches[6] ?? 0);

        return ($days * 86400) + ($hours * 3600) + ($minutes * 60) + $seconds;
    }

    private function formatSecondsAsTimestamp(int $seconds): string
    {
        return sprintf(
            '%02d:%02d:%02d',
            intdiv($seconds, 3600),
            intdiv($seconds % 3600, 60),
            $seconds % 60
        );
    }

    private function debugUrlType(string $url): string
    {
        $trimmed = trim($url);

        if ($this->isDirectHttpUrl($trimmed)) {
            return 'direct_http';
        }

        if ($trimmed === '') {
            return 'empty';
        }

        return 'encrypted_or_unknown';
    }

    private function resolveMpdUrl(string $raw): string
    {
        $raw = trim($raw);

        if ($this->isDirectHttpUrl($raw)) {
            return $raw;
        }

        $rawParam = rawurldecode($raw);
        $rawParam = str_replace(' ', '+', $rawParam);
        $b64 = strtr($rawParam, '-_', '+/');
        $pad = strlen($b64) % 4;

        if ($pad) {
            $b64 .= str_repeat('=', 4 - $pad);
        }

        $data = base64_decode($b64, true);

        if ($data === false || strlen($data) < 17) {
            throw new \InvalidArgumentException('Invalid MPD URL or encrypted payload.');
        }

        $iv = substr($data, 0, 16);
        $cipherText = substr($data, 16);
        $decryptionKey = hash(
            'sha256',
            'd4c6198dabafb243b0d043a3c33a9fe171f81605158c267c7dfe5f66df29559a',
            true
        );

        $decryptedMessage = openssl_decrypt(
            $cipherText,
            'aes-256-cbc',
            $decryptionKey,
            OPENSSL_RAW_DATA,
            $iv
        );

        if ($decryptedMessage === false) {
            throw new \InvalidArgumentException('Failed to decrypt MPD URL.');
        }

        $mpdUrl = trim(str_replace(["\r", "\n"], '', $decryptedMessage));
        $mpdUrl = filter_var($mpdUrl, FILTER_VALIDATE_URL) ? $mpdUrl : urldecode($mpdUrl);

        if (!$this->isDirectHttpUrl($mpdUrl)) {
            throw new \InvalidArgumentException('Decrypted payload is not a valid stream URL.');
        }

        return $mpdUrl;
    }

    private function isDirectHttpUrl(string $url): bool
    {
        return preg_match('/^https?:\/\//i', $url)
            && filter_var($url, FILTER_VALIDATE_URL);
    }
}
