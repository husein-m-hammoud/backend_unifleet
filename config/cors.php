<?php

return [
    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    // Allow the React/Vite frontend dev server + production domain
    'allowed_origins' => [
        'http://localhost:8089',
        'http://localhost:8080',
        'http://localhost:3000',
        'http://127.0.0.1:8089',
        'http://127.0.0.1:8080',
        'http://127.0.0.1:3000',
        // Add your production frontend URL here when deploying
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    // Must be true when frontend sends Authorization: Bearer header
    'supports_credentials' => true,
];
