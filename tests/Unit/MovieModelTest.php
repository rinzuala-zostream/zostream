<?php

namespace Tests\Unit;

use App\Models\MovieModel;
use PHPUnit\Framework\TestCase;

class MovieModelTest extends TestCase
{
    public function test_empty_subtitle_is_normalized_for_legacy_not_null_column(): void
    {
        $movie = new MovieModel();

        $movie->fill(['subtitle' => null]);

        $this->assertSame('', $movie->getAttributes()['subtitle']);
    }

    public function test_non_empty_subtitle_is_preserved(): void
    {
        $movie = new MovieModel();

        $movie->fill(['subtitle' => 'https://cdn.example.com/movie.vtt']);

        $this->assertSame(
            'https://cdn.example.com/movie.vtt',
            $movie->getAttributes()['subtitle']
        );
    }
}
