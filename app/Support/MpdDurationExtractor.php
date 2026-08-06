<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class MpdDurationExtractor
{
    private const DECRYPTION_SECRET = 'd4c6198dabafb243b0d043a3c33a9fe171f81605158c267c7dfe5f66df29559a';

    public function extract(?string $source): ?string
    {
        if ($source === null || trim($source) === '') {
            return null;
        }

        try {
            $mpdUrl = $this->resolveUrl($source);

            if ($mpdUrl === null) {
                return null;
            }

            $response = Http::connectTimeout(2)
                ->timeout(10)
                ->accept('application/dash+xml, application/xml, text/xml, */*')
                ->withUserAgent('ZoStream/1.0 MPD duration reader')
                ->get(str_replace(' ', '%20', $mpdUrl));

            if (! $response->successful()) {
                Log::warning('Movie MPD duration request failed', [
                    'status' => $response->status(),
                    'host' => parse_url($mpdUrl, PHP_URL_HOST),
                ]);

                return null;
            }

            $body = ltrim($response->body());
            $xml = @simplexml_load_string(
                $body,
                \SimpleXMLElement::class,
                LIBXML_NONET | LIBXML_NOCDATA
            );

            if (! $xml || strcasecmp($xml->getName(), 'MPD') !== 0) {
                Log::warning('Movie duration URL did not return an MPD manifest', [
                    'host' => parse_url($mpdUrl, PHP_URL_HOST),
                    'content_type' => $response->header('Content-Type'),
                ]);

                return null;
            }

            $isoDuration = (string) ($xml['mediaPresentationDuration'] ?? '');

            if ($isoDuration !== '') {
                return $this->formatIso8601($isoDuration);
            }

            return $this->formatPeriodDurations($xml);
        } catch (Throwable $e) {
            Log::warning('Movie MPD duration extraction failed', [
                'source_type' => $this->isHttpUrl(trim($source)) ? 'direct_url' : 'encrypted',
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function formatIso8601(string $duration): ?string
    {
        if (! preg_match(
            '/^P(?:(\d+)Y)?(?:(\d+)M)?(?:(\d+)D)?T?(?:(\d+)H)?(?:(\d+)M)?(?:(\d+(?:\.\d+)?)S)?$/',
            trim($duration),
            $matches
        )) {
            return null;
        }

        $days = (int) ($matches[3] ?? 0);
        $hours = (int) ($matches[4] ?? 0) + ($days * 24);
        $minutes = (int) ($matches[5] ?? 0);
        $seconds = (float) ($matches[6] ?? 0);
        $totalMinutes = max(1, (int) round(($hours * 60) + $minutes + ($seconds / 60)));
        $formattedHours = intdiv($totalMinutes, 60);
        $formattedMinutes = $totalMinutes % 60;

        return $formattedHours > 0
            ? "{$formattedHours}h {$formattedMinutes}m"
            : "{$formattedMinutes}m";
    }

    private function resolveUrl(string $source): ?string
    {
        $source = trim($source);

        if ($this->isHttpUrl($source)) {
            // Signed/tokenised manifest endpoints do not always end in `.mpd`.
            // Fetch the URL and verify the response is an MPD instead.
            return html_entity_decode($source, ENT_QUOTES | ENT_HTML5);
        }

        $encoded = str_replace(' ', '+', rawurldecode($source));
        $encoded = strtr($encoded, '-_', '+/');
        $padding = strlen($encoded) % 4;

        if ($padding > 0) {
            $encoded .= str_repeat('=', 4 - $padding);
        }

        $payload = base64_decode($encoded, true);

        if ($payload === false || strlen($payload) < 17) {
            return null;
        }

        $decrypted = openssl_decrypt(
            substr($payload, 16),
            'aes-256-cbc',
            hash('sha256', self::DECRYPTION_SECRET, true),
            OPENSSL_RAW_DATA,
            substr($payload, 0, 16)
        );

        if ($decrypted === false) {
            return null;
        }

        $url = trim(str_replace(["\r", "\n"], '', $decrypted));
        $url = $this->isHttpUrl($url) ? $url : urldecode($url);

        return $this->isHttpUrl($url)
            ? html_entity_decode($url, ENT_QUOTES | ENT_HTML5)
            : null;
    }

    private function formatPeriodDurations(\SimpleXMLElement $xml): ?string
    {
        $periods = $xml->xpath('//*[local-name()="Period"]') ?: [];
        $totalSeconds = 0.0;
        $found = false;

        foreach ($periods as $period) {
            $seconds = $this->iso8601ToSeconds((string) ($period['duration'] ?? ''));

            if ($seconds !== null) {
                $totalSeconds += $seconds;
                $found = true;
            }
        }

        return $found ? $this->formatSeconds($totalSeconds) : null;
    }

    private function iso8601ToSeconds(string $duration): ?float
    {
        if (! preg_match(
            '/^P(?:(\d+)Y)?(?:(\d+)M)?(?:(\d+)D)?T?(?:(\d+)H)?(?:(\d+)M)?(?:(\d+(?:\.\d+)?)S)?$/',
            trim($duration),
            $matches
        )) {
            return null;
        }

        return ((int) ($matches[3] ?? 0) * 86400)
            + ((int) ($matches[4] ?? 0) * 3600)
            + ((int) ($matches[5] ?? 0) * 60)
            + (float) ($matches[6] ?? 0);
    }

    private function formatSeconds(float $seconds): string
    {
        $totalMinutes = max(1, (int) round($seconds / 60));
        $hours = intdiv($totalMinutes, 60);
        $minutes = $totalMinutes % 60;

        return $hours > 0 ? "{$hours}h {$minutes}m" : "{$minutes}m";
    }

    private function isHttpUrl(string $url): bool
    {
        return preg_match('/^https?:\/\//i', $url) === 1
            && filter_var($url, FILTER_VALIDATE_URL) !== false;
    }
}
