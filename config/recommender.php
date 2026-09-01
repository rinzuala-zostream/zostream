<?php

$defaultConnection = (string) config('database.default');
$defaultDatabase = (array) config("database.connections.{$defaultConnection}", []);
$absolutePath = static function (?string $path, string $fallback): string {
    $path = $path ?: $fallback;

    return str_starts_with($path, DIRECTORY_SEPARATOR) ? $path : base_path($path);
};

return [
    'python' => env(
        'RECOMMENDER_PYTHON',
        is_file(base_path('recommender/.venv/bin/python'))
            ? base_path('recommender/.venv/bin/python')
            : 'python3'
    ),
    'script' => $absolutePath(env('RECOMMENDER_SCRIPT'), 'recommender/hybrid.py'),
    'model' => $absolutePath(env('RECOMMENDER_MODEL'), 'recommender/artifacts/hybrid_model.json.gz'),
    'timeout_seconds' => (float) env('RECOMMENDER_TIMEOUT_SECONDS', 30),
    'cache_seconds' => (int) env('RECOMMENDER_CACHE_SECONDS', 300),
    'train_source' => env('RECOMMENDER_TRAIN_SOURCE', 'mysql'),
    'train_data_dir' => env('RECOMMENDER_TRAIN_DATA_DIR', base_path()),
    'train_timeout_seconds' => (int) env('RECOMMENDER_TRAIN_TIMEOUT_SECONDS', 3600),
    'train_schedule' => env('RECOMMENDER_TRAIN_SCHEDULE', '0 3 * * *'),
    'train_timezone' => env('RECOMMENDER_TRAIN_TIMEZONE', config('app.timezone', 'UTC')),
    'train_mysql' => [
        'driver' => 'mysql',
        'url' => env('RECOMMENDER_TRAIN_DB_URL'),
        'host' => env('RECOMMENDER_TRAIN_DB_HOST', $defaultDatabase['host'] ?? '127.0.0.1'),
        'port' => env('RECOMMENDER_TRAIN_DB_PORT', $defaultDatabase['port'] ?? '3306'),
        'database' => env('RECOMMENDER_TRAIN_DB_DATABASE', $defaultDatabase['database'] ?? ''),
        'username' => env('RECOMMENDER_TRAIN_DB_USERNAME', $defaultDatabase['username'] ?? ''),
        'password' => env('RECOMMENDER_TRAIN_DB_PASSWORD', $defaultDatabase['password'] ?? ''),
        'unix_socket' => env('RECOMMENDER_TRAIN_DB_SOCKET', $defaultDatabase['unix_socket'] ?? ''),
        'charset' => env('RECOMMENDER_TRAIN_DB_CHARSET', $defaultDatabase['charset'] ?? 'utf8mb4'),
        'prefix' => '',
    ],
    'backup_pattern' => env('RECOMMENDER_BACKUP_PATTERN', '/var/backups/mysql/zo_stream_db_*.sql.gz'),
    'backup_max_age_hours' => (int) env('RECOMMENDER_BACKUP_MAX_AGE_HOURS', 30),
    'backup_restore_timeout_seconds' => (int) env('RECOMMENDER_BACKUP_RESTORE_TIMEOUT_SECONDS', 1800),
    'training_database' => env('RECOMMENDER_TRAINING_DATABASE', 'zo_stream_recommender_training'),
    'mysql_binary' => env('RECOMMENDER_MYSQL_BINARY', 'mysql'),
    'gzip_binary' => env('RECOMMENDER_GZIP_BINARY', 'gzip'),
];
