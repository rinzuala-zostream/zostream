<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

class NexframePlayStoreAnalytics
{
    public function __construct(private readonly NexframePlayStoreReportClient $reports) {}

    public function report(Carbon $from, Carbon $to, bool $refresh = false): array
    {
        $config = config('services.nexframe_dashboard.play_store', []);
        $packageName = trim((string) ($config['package_name'] ?? ''));
        $bucket = trim((string) ($config['bucket'] ?? ''));
        $configured = $packageName !== '' && $bucket !== '';
        $empty = $this->emptyReport($configured, $packageName);

        if (! $configured) {
            return $empty;
        }

        $credentialsPath = trim((string) ($config['credentials_path'] ?? ''));
        $credentialsVersion = $credentialsPath !== '' && is_file($credentialsPath)
            ? (string) filemtime($credentialsPath)
            : 'adc';
        $identity = [
            $packageName, $bucket, $config['project_id'] ?? null,
            $config['installs_dimension'] ?? null, $config['store_performance_dimension'] ?? null,
            $credentialsVersion, $from->toDateString(), $to->toDateString(),
        ];
        $cache = Cache::store(config('services.nexframe_dashboard.business_cache_store', 'file'));
        $key = 'nexframe:play-store:v1:'.hash('sha256', json_encode($identity));

        if ($refresh) {
            $cache->forget($key);
        }

        return $cache->remember($key, now()->addHour(), function () use ($config, $packageName, $from, $to, $empty) {
            try {
                $daily = [];
                $sources = [];
                $files = [];

                foreach ($this->months($from, $to) as $month) {
                    $installs = $this->monthlyReport(
                        $config,
                        'stats/installs/installs_'.$packageName.'_'.$month,
                        (string) ($config['installs_dimension'] ?? 'overview'),
                    );
                    if ($installs) {
                        $files[] = $installs['name'];
                        $sources['installs'] = true;
                        $this->mergeInstalls($daily, $this->parseCsv($installs['contents']), $from, $to);
                    }

                    $store = $this->monthlyReport(
                        $config,
                        'stats/store_performance/store_performance_'.$packageName.'_'.$month,
                        (string) ($config['store_performance_dimension'] ?? 'country'),
                    );
                    if ($store) {
                        $files[] = $store['name'];
                        $sources['store_performance'] = true;
                        $this->mergeStorePerformance($daily, $this->parseCsv($store['contents']), $from, $to);
                    }
                }

                ksort($daily);
                $asOf = collect(array_keys($daily))->max();
                $installs = $this->sumAvailable($daily, 'installs');
                $uninstalls = $this->sumAvailable($daily, 'uninstalls');
                $visitors = $this->sumAvailable($daily, 'store_visitors');
                $acquisitions = $this->sumAvailable($daily, 'store_acquisitions');
                $latestActive = collect($daily)->reverse()->first(fn ($row) => ($row['active_device_installs'] ?? null) !== null)['active_device_installs'] ?? null;

                return [
                    ...$empty,
                    'connected' => true,
                    'healthy' => $sources !== [],
                    'status' => count($sources) === 2 ? 'healthy' : ($sources ? 'partial' : 'unavailable'),
                    'package_name' => $packageName,
                    'as_of' => $asOf,
                    'reporting_delay_days' => 7,
                    'sources' => array_keys($sources),
                    'files' => $files,
                    'metrics' => [
                        'installs' => $installs,
                        'uninstalls' => $uninstalls,
                        'active_device_installs' => $latestActive,
                        'store_visitors' => $visitors,
                        'store_acquisitions' => $acquisitions,
                        'store_conversion_rate' => ($visitors ?? 0) > 0 && $acquisitions !== null
                            ? round(($acquisitions / $visitors) * 100, 2)
                            : null,
                    ],
                    'daily' => $daily,
                    'message' => $sources === []
                        ? 'No Google Play report files were available for the selected dates.'
                        : null,
                    'last_successful_sync' => $sources ? now()->toIso8601String() : null,
                ];
            } catch (Throwable) {
                return [
                    ...$empty,
                    'connected' => false,
                    'healthy' => false,
                    'status' => 'unavailable',
                    'message' => 'Google Play report sync failed. Check the bucket, service account and Play Console permissions.',
                ];
            }
        });
    }

    private function monthlyReport(array $config, string $prefix, string $dimension): ?array
    {
        return $this->reports->downloadFirst($config, $prefix, [
            '_'.$dimension.'.csv',
            '.csv',
        ]);
    }

    private function months(Carbon $from, Carbon $to): array
    {
        $cursor = $from->copy()->startOfMonth();
        $months = [];
        while ($cursor->lte($to)) {
            $months[] = $cursor->format('Ym');
            $cursor->addMonth();
        }

        return $months;
    }

    private function parseCsv(string $contents): array
    {
        if (str_starts_with($contents, "\xFF\xFE")) {
            $contents = mb_convert_encoding(substr($contents, 2), 'UTF-8', 'UTF-16LE');
        } elseif (str_starts_with($contents, "\xFE\xFF")) {
            $contents = mb_convert_encoding(substr($contents, 2), 'UTF-8', 'UTF-16BE');
        } elseif (str_contains(substr($contents, 0, 200), "\0")) {
            $contents = mb_convert_encoding($contents, 'UTF-8', 'UTF-16LE');
        }

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, preg_replace('/^\xEF\xBB\xBF/', '', $contents));
        rewind($stream);
        $header = fgetcsv($stream, null, ',', '"', '');
        if (! is_array($header)) {
            fclose($stream);

            return [];
        }
        $header = array_map(fn ($value) => $this->header((string) $value), $header);
        $rows = [];
        while (($values = fgetcsv($stream, null, ',', '"', '')) !== false) {
            if (count($values) !== count($header)) {
                continue;
            }
            $rows[] = array_combine($header, $values);
        }
        fclose($stream);

        return $rows;
    }

    private function mergeInstalls(array &$daily, array $rows, Carbon $from, Carbon $to): void
    {
        foreach ($rows as $row) {
            $date = $this->date($row['date'] ?? null, $from, $to);
            if (! $date) {
                continue;
            }
            $installs = $this->number($row, ['daily_user_installs', 'daily_device_installs']);
            $uninstalls = $this->number($row, ['daily_user_uninstalls', 'daily_device_uninstalls']);
            $active = $this->number($row, ['installs_on_active_devices', 'current_device_installs']);
            $daily[$date] ??= $this->emptyDay();
            $daily[$date]['installs'] = $this->add($daily[$date]['installs'], $installs);
            $daily[$date]['uninstalls'] = $this->add($daily[$date]['uninstalls'], $uninstalls);
            $daily[$date]['active_device_installs'] = $this->add($daily[$date]['active_device_installs'], $active);
        }
    }

    private function mergeStorePerformance(array &$daily, array $rows, Carbon $from, Carbon $to): void
    {
        foreach ($rows as $row) {
            $date = $this->date($row['date'] ?? null, $from, $to);
            if (! $date) {
                continue;
            }
            $daily[$date] ??= $this->emptyDay();
            $daily[$date]['store_visitors'] = $this->add(
                $daily[$date]['store_visitors'],
                $this->number($row, ['store_listing_visitors', 'store_listing_clicks']),
            );
            $daily[$date]['store_acquisitions'] = $this->add(
                $daily[$date]['store_acquisitions'],
                $this->number($row, ['store_listing_acquisitions']),
            );
        }
    }

    private function date(?string $value, Carbon $from, Carbon $to): ?string
    {
        try {
            $date = Carbon::createFromFormat('Y-m-d', trim((string) $value))->startOfDay();

            return $date->betweenIncluded($from->copy()->startOfDay(), $to->copy()->startOfDay())
                ? $date->toDateString()
                : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function number(array $row, array $keys): ?int
    {
        foreach ($keys as $key) {
            $value = str_replace([',', ' '], '', (string) ($row[$key] ?? ''));
            if ($value !== '' && is_numeric($value)) {
                return (int) round((float) $value);
            }
        }

        return null;
    }

    private function header(string $value): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($value))), '_');
    }

    private function add(?int $current, ?int $value): ?int
    {
        return $value === null ? $current : ($current ?? 0) + $value;
    }

    private function sumAvailable(array $rows, string $key): ?int
    {
        $values = collect($rows)->pluck($key)->filter(fn ($value) => $value !== null);

        return $values->isEmpty() ? null : (int) $values->sum();
    }

    private function emptyDay(): array
    {
        return [
            'installs' => null,
            'uninstalls' => null,
            'active_device_installs' => null,
            'store_visitors' => null,
            'store_acquisitions' => null,
        ];
    }

    private function emptyReport(bool $configured, string $packageName): array
    {
        return [
            'configured' => $configured,
            'connected' => false,
            'healthy' => false,
            'status' => 'unavailable',
            'package_name' => $packageName ?: null,
            'as_of' => null,
            'reporting_delay_days' => 7,
            'sources' => [],
            'files' => [],
            'metrics' => [
                'installs' => null,
                'uninstalls' => null,
                'active_device_installs' => null,
                'store_visitors' => null,
                'store_acquisitions' => null,
                'store_conversion_rate' => null,
            ],
            'daily' => [],
            'last_successful_sync' => null,
            'message' => $configured ? 'Google Play reports have not been verified.' : 'Google Play reports are not configured.',
        ];
    }
}
