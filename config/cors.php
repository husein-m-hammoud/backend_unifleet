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

    /*
     * Loopback origins on ANY port — dev only.
     *
     * Vite silently falls back to the next free port (8089 -> 8090 -> 8091) when
     * its configured one is taken, and the resulting blocked preflight surfaces
     * in the browser as a generic "CORS error / Failed to fetch" with almost no
     * response headers — which is very hard to read as "wrong port".
     *
     * Scoped to localhost/127.0.0.1 and disabled in production, so it can never
     * widen the real deployment: prod origins must be listed explicitly above.
     */
    'allowed_origins_patterns' => env('APP_ENV') === 'production' ? [] : [
        '#^http://(localhost|127\.0\.0\.1)(:\d+)?$#',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    // Must be true when frontend sends Authorization: Bearer header
    'supports_credentials' => true,
];
