<?php

namespace Tests\Unit;

use App\Support\MpdDurationExtractor;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MpdDurationExtractorTest extends TestCase
{
    public function test_it_extracts_and_formats_a_direct_mpd_duration(): void
    {
        Http::fake([
            'https://cdn.example.com/movie.mpd' => Http::response(
                '<?xml version="1.0"?><MPD mediaPresentationDuration="PT1H12M" />'
            ),
        ]);

        $duration = (new MpdDurationExtractor)->extract(
            'https://cdn.example.com/movie.mpd'
        );

        $this->assertSame('1h 12m', $duration);
    }

    public function test_it_formats_minutes_and_rounds_seconds(): void
    {
        $extractor = new MpdDurationExtractor;

        $this->assertSame('48m', $extractor->formatIso8601('PT47M31S'));
        $this->assertSame('2h 0m', $extractor->formatIso8601('PT1H59M45S'));
    }

    public function test_it_ignores_a_non_mpd_url(): void
    {
        Http::fake([
            'https://cdn.example.com/movie.mp4' => Http::response(
                'not a manifest',
                200,
                ['Content-Type' => 'video/mp4']
            ),
        ]);

        $duration = (new MpdDurationExtractor)->extract(
            'https://cdn.example.com/movie.mp4'
        );

        $this->assertNull($duration);
        Http::assertSentCount(1);
    }

    public function test_it_accepts_a_manifest_endpoint_without_mpd_in_the_url(): void
    {
        Http::fake([
            'https://cdn.example.com/playback?token=abc' => Http::response(
                '<?xml version="1.0"?><MPD mediaPresentationDuration="PT1H12M" />'
            ),
        ]);

        $this->assertSame(
            '1h 12m',
            (new MpdDurationExtractor)->extract('https://cdn.example.com/playback?token=abc')
        );
    }

    public function test_it_uses_period_duration_when_root_duration_is_missing(): void
    {
        Http::fake([
            'https://cdn.example.com/movie.mpd' => Http::response(
                '<?xml version="1.0"?><MPD xmlns="urn:mpeg:dash:schema:mpd:2011"><Period duration="PT35M"/><Period duration="PT37M"/></MPD>'
            ),
        ]);

        $this->assertSame(
            '1h 12m',
            (new MpdDurationExtractor)->extract('https://cdn.example.com/movie.mpd')
        );
    }

    public function test_it_extracts_duration_from_an_encrypted_mpd_link(): void
    {
        $url = 'https://cdn.example.com/encrypted-movie.mpd';
        $iv = str_repeat('a', 16);
        $key = hash(
            'sha256',
            'd4c6198dabafb243b0d043a3c33a9fe171f81605158c267c7dfe5f66df29559a',
            true
        );
        $ciphertext = openssl_encrypt(
            $url,
            'aes-256-cbc',
            $key,
            OPENSSL_RAW_DATA,
            $iv
        );
        $payload = rtrim(strtr(base64_encode($iv.$ciphertext), '+/', '-_'), '=');

        Http::fake([
            $url => Http::response(
                '<?xml version="1.0"?><MPD mediaPresentationDuration="PT2H5M" />'
            ),
        ]);

        $this->assertSame('2h 5m', (new MpdDurationExtractor)->extract($payload));
    }

    public function test_it_returns_null_when_the_manifest_has_no_duration(): void
    {
        Http::fake([
            'https://cdn.example.com/movie.mpd' => Http::response(
                '<?xml version="1.0"?><MPD />'
            ),
        ]);

        $this->assertNull(
            (new MpdDurationExtractor)->extract('https://cdn.example.com/movie.mpd')
        );
    }
}
