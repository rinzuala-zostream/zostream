<?php

return [
    'python' => env(
        'RECOMMENDER_PYTHON',
        is_file(base_path('recommender/.venv/bin/python'))
            ? base_path('recommender/.venv/bin/python')
            : 'python3'
    ),
    'script' => env('RECOMMENDER_SCRIPT', base_path('recommender/hybrid.py')),
    'model' => env('RECOMMENDER_MODEL', base_path('recommender/artifacts/hybrid_model.json.gz')),
    'timeout_seconds' => (float) env('RECOMMENDER_TIMEOUT_SECONDS', 30),
    'cache_seconds' => (int) env('RECOMMENDER_CACHE_SECONDS', 300),
    'train_source' => env('RECOMMENDER_TRAIN_SOURCE', 'mysql'),
    'train_data_dir' => env('RECOMMENDER_TRAIN_DATA_DIR', base_path()),
    'train_timeout_seconds' => (int) env('RECOMMENDER_TRAIN_TIMEOUT_SECONDS', 3600),
    'train_schedule' => env('RECOMMENDER_TRAIN_SCHEDULE', '0 3 * * *'),
    'train_timezone' => env('RECOMMENDER_TRAIN_TIMEZONE', config('app.timezone', 'UTC')),
];
