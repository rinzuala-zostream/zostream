<?php

namespace App\Support\Catalog;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Throwable;

/** Shared, revision-keyed ranking index for public movie recommendations. */
final class MovieRecommendationIndex
{
    private const CANDIDATE_TTL_SECONDS = 3600;
    private const RANKING_TTL_SECONDS = 900;

    /**
     * @return array{matched: bool, match_id?: mixed, match_title?: string, sequels: array, related: array, candidates: array}
     */
    public function ranking(string $title, bool $ageRestriction, bool $onlyMizo, bool $kidsMode): array
    {
        $revision = MovieCatalogRevision::current();
        $audience = ['age' => $ageRestriction, 'mizo' => $onlyMizo, 'kids' => $kidsMode];
        $audienceHash = hash('sha256', json_encode($audience, JSON_THROW_ON_ERROR));
        $candidateKey = "recommendations:candidates:v1:{$revision}:{$audienceHash}";
        $candidateLock = "recommendations:candidates-lock:v1:{$revision}:{$audienceHash}";
        $candidates = $this->rememberLocked(
            $candidateKey,
            $candidateLock,
            self::CANDIDATE_TTL_SECONDS,
            fn (): array => $this->loadCandidates($ageRestriction, $onlyMizo, $kidsMode),
        );
        $rows = $candidates['rows'];

        // Cache title-specific fuzzy scores only for canonical catalog titles.
        // The legacy query endpoint accepts arbitrary text; avoiding cache
        // entries for attacker-controlled titles prevents unbounded key growth.
        if (! isset($candidates['exact_titles'][$title])) {
            return $this->rank($title, $rows) + ['candidates' => $rows];
        }

        $rankingKey = 'recommendations:ranking:v1:'.hash('sha256', $revision."\0".$audienceHash."\0".$title);

        return $this->rememberLocked(
            $rankingKey,
            $rankingKey.':lock',
            self::RANKING_TTL_SECONDS,
            fn (): array => $this->rank($title, $rows),
        ) + ['candidates' => $rows];
    }

    private function loadCandidates(bool $ageRestriction, bool $onlyMizo, bool $kidsMode): array
    {
        $query = DB::table('movie')->where('isEnable', 1)->where('status', 'Published');

        if (! $ageRestriction) {
            $query->where('isAgeRestricted', 0);
        }
        if ($kidsMode) {
            $query->where('isChildMode', 1);
        }
        if ($onlyMizo) {
            $query->where('isMizo', 1);
        }

        // The legacy API serializes every movie column in its result. Keep that
        // response contract out of the large ranking scan: only load the fields
        // required for matching, with no Eloquent hydration or date casts.
        $rows = $query->orderBy('num')->get([
            'num', 'id', 'title', 'genre', 'isBollywood', 'isHollywood',
            'isKorean', 'isMizo', 'isDocumentary', 'isSeason',
        ])->map(static fn (object $movie): array => (array) $movie)->all();

        foreach ($rows as &$movie) {
            $rawTitle = (string) ($movie['title'] ?? '');
            $movie['_normalized_title'] = strtolower(trim($rawTitle));
            $movie['_normalized_title_length'] = strlen($movie['_normalized_title']);
            $movie['_title_length'] = strlen($rawTitle);
        }
        unset($movie);

        return [
            'rows' => $rows,
            'exact_titles' => array_fill_keys(array_column($rows, 'title'), true),
        ];
    }

    private function rank(string $inputTitle, array $candidates): array
    {
        $bestMatch = null;
        $bestScore = 0.0;
        $normalizedInput = strtolower($inputTitle);
        $inputLength = strlen($normalizedInput);

        foreach ($candidates as $index => $movie) {
            $candidateLength = $movie['_normalized_title_length'];
            $possibleScore = ($inputLength + $candidateLength) === 0
                ? 0
                : 200 * min($inputLength, $candidateLength) / ($inputLength + $candidateLength);
            if ($possibleScore <= $bestScore) {
                continue;
            }

            similar_text($normalizedInput, $movie['_normalized_title'], $score);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestMatch = $movie;
            }
        }

        if ($bestScore < 60 || $bestMatch === null) {
            return ['matched' => false, 'sequels' => [], 'related' => []];
        }

        $sequels = [];
        $related = [];
        $matchTitle = $bestMatch['title'];
        $matchLength = strlen((string) $matchTitle);
        $matchGenre = $bestMatch['genre'];
        $categories = ['isBollywood', 'isHollywood', 'isKorean', 'isMizo', 'isDocumentary', 'isSeason'];

        // One pass replaces separate sequel and genre/category scans. Keep the
        // exact thresholds, case behavior, category rules and insertion order.
        foreach ($candidates as $index => $movie) {
            if ($movie['title'] === $matchTitle) {
                continue;
            }

            $movieLength = $movie['_title_length'];
            $possibleScore = ($matchLength + $movieLength) === 0
                ? 0
                : 200 * min($matchLength, $movieLength) / ($matchLength + $movieLength);
            if ($possibleScore > 80) {
                similar_text((string) $matchTitle, (string) $movie['title'], $score);
                if ($score > 80) {
                    $sequels[$movie['id']] = $index;
                }
            }

            if (strpos((string) ($movie['genre'] ?? ''), (string) ($matchGenre ?? '')) !== false) {
                foreach ($categories as $category) {
                    if (! empty($bestMatch[$category]) && ! empty($movie[$category])) {
                        $related[$movie['id']] = $index;
                        break;
                    }
                }
            }
        }

        return [
            'matched' => true,
            'match_id' => $bestMatch['id'],
            'match_title' => (string) $bestMatch['title'],
            'sequels' => $sequels,
            'related' => $related,
        ];
    }

    private function rememberLocked(string $key, string $lockKey, int $ttl, callable $loader): mixed
    {
        $cache = Cache::store();

        try {
            if ($cache->has($key)) {
                return $cache->get($key);
            }

            return $cache->lock($lockKey, 30)->block(5, function () use ($cache, $key, $ttl, $loader): mixed {
                if ($cache->has($key)) {
                    return $cache->get($key);
                }

                $value = $loader();
                $cache->put($key, $value, $ttl);

                return $value;
            });
        } catch (LockTimeoutException $error) {
            // Do not turn a lock timeout into a concurrent cold rebuild. The
            // caller can retry after the in-flight refresh populates the key.
            throw $error;
        } catch (Throwable $error) {
            // Cache outages must not make the public recommendation endpoint
            // unavailable. The un-cached path retains the same result rules.
            report($error);

            return $loader();
        }
    }
}
