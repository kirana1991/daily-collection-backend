<?php

$allowedOrigins = array_filter(array_map(
    'trim',
    explode(',', env('CORS_ALLOWED_ORIGINS', 'https://dc.scorein.co.in,http://localhost:5173,http://127.0.0.1:5173'))
));

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
