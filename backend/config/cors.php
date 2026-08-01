<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_filter([
        env('SPA_URL', 'http://localhost:5173'),
        'http://localhost:5173',
        'http://127.0.0.1:5173',
    ]),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [
        'X-Examora-Lesson-Quota-Limit',
        'X-Examora-Lesson-Quota-Used',
    ],

    'max_age' => 0,

    // JWT bearer tokens, not cookies — no credentialed requests needed.
    'supports_credentials' => false,
];
