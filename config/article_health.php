<?php

return [
    'tokenizer_url' => env('PYTHON_CONTAINER_NAME', 'http://127.0.0.1:8678'),
    'tokenizer_timeout_seconds' => (int) env('ARTICLE_HEALTH_TOKENIZER_TIMEOUT', 3),
    'scan_limit' => (int) env('ARTICLE_HEALTH_SCAN_LIMIT', 1000),
    'sample_limit' => (int) env('ARTICLE_HEALTH_SAMPLE_LIMIT', 20),
    'max_processed_text_bytes' => (int) env('ARTICLE_HEALTH_MAX_PROCESSED_TEXT_BYTES', 8 * 1024 * 1024),
    'fallback_minimum_senses' => (int) env('ARTICLE_HEALTH_FALLBACK_MINIMUM', 10),
    'fallback_warning_ratio' => (float) env('ARTICLE_HEALTH_FALLBACK_RATIO', 0.25),
];
