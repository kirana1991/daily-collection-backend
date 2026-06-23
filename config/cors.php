<?php

$originList = implode(',', array_filter([
    env('CORS_ALLOWED_ORIGINS'),
    env('FRONTEND_URL'),
    'https://dc.scorein.co.in',
    'http://localhost:5173',
    'http://127.0.0.1:5173',
]));

$allowedOrigins = array_values(array_unique(array_filter(array_map(
    fn (string $origin) => rtrim(trim($origin), '/'),
    explode(',', $originList)
))));

return [
    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => $allowedOrigins,

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,
];
