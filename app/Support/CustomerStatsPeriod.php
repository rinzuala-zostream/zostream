<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class CustomerStatsPeriod
{
    public static function filters(Request $request): array
    {
        $filters = $request->validate([
            'period' => ['nullable', Rule::in(['today', 'week', 'month', 'last_month', 'custom'])],
            'from' => ['required_if:period,custom', 'nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'to' => ['required_if:period,custom', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from', 'before_or_equal:today'],
            'q' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', Rule::in(['all', 'movie', 'episode'])],
            'sort' => ['nullable', Rule::in(['latest', 'watch_time', 'data'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:20'],
            'status' => ['nullable', Rule::in(['all', 'paid', 'pending', 'failed', 'refunded'])],
            'device' => ['nullable', Rule::in(['all', 'mobile', 'tv', 'browser', 'desktop'])],
        ]);
        [$from, $to] = self::range($filters);
        if ($from->diffInDays($to) > 366) {
            throw ValidationException::withMessages(['to' => 'Choose a date range of up to one year.']);
        }

        return $filters;
    }

    public static function range(array $filters = []): array
    {
        $now = Carbon::now();

        return match ($filters['period'] ?? 'month') {
            'today' => [$now->copy()->startOfDay(), $now],
            'week' => [$now->copy()->subDays(6)->startOfDay(), $now],
            'last_month' => [$now->copy()->subMonthNoOverflow()->startOfMonth(), $now->copy()->startOfMonth()->subMicrosecond()],
            'custom' => [Carbon::parse($filters['from'])->startOfDay(), Carbon::parse($filters['to'])->endOfDay()->min($now)],
            default => [$now->copy()->startOfMonth(), $now],
        };
    }
}
