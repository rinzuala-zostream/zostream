<?php

namespace App\Services\Chat;

use Kreait\Firebase\Contract\Database;
use Kreait\Firebase\Database\Transaction;
use Kreait\Firebase\Factory;

class FirebaseChatStore
{
    private ?Database $database = null;

    private function factory(): Factory
    {
        return (new Factory)->withServiceAccount((string) config('firebase.credentials'));
    }

    private function database(): Database
    {
        return $this->database ??= $this->factory()
            ->withDatabaseUri((string) config('firebase.database_url'))->createDatabase();
    }

    public function get(string $path): mixed
    {
        return $this->database()->getReference('chat_v1/'.$path)->getValue();
    }

    public function update(array $values): void
    {
        $updates = [];
        foreach ($values as $path => $value) {
            $updates['chat_v1/'.$path] = $value;
        }
        $this->database()->getReference()->update($updates);
    }

    public function query(string $path, string $order = 'cursor', int|string|null $start = null, int|string|null $end = null, ?int $limit = null, bool $first = false): array
    {
        $query = $this->database()->getReference('chat_v1/'.$path);
        $query = match ($order) {
            '$key' => $query->orderByKey(), '$value' => $query->orderByValue(), default => $query->orderByChild($order)
        };
        if ($start !== null) {
            $query = $query->startAt($order === '$key' ? self::key($start) : $start);
        }
        if ($end !== null) {
            $query = $query->endAt($order === '$key' ? self::key($end) : $end);
        }
        if ($limit !== null) {
            $query = $first ? $query->limitToFirst($limit) : $query->limitToLast($limit);
        }

        return $query->getValue() ?: [];
    }

    public static function key(int|string $cursor): string
    {
        return str_pad((string) $cursor, 20, '0', STR_PAD_LEFT);
    }

    public function nextCursor(): int
    {
        $ref = $this->database()->getReference('chat_v1/last_cursor');

        return retry(5, fn () => $this->database()->runTransaction(function (Transaction $transaction) use ($ref) {
            $previous = (int) $transaction->snapshot($ref)->getValue();
            $next = max(now()->valueOf() * 1000, $previous + 1);
            $transaction->set($ref, $next);

            return $next;
        }), 10, fn ($e) => $e instanceof \Kreait\Firebase\Exception\Database\TransactionFailed);
    }

    public function customToken(string $uid, array $claims): string
    {
        return $this->factory()->createAuth()->createCustomToken($uid, $claims)->toString();
    }
}
