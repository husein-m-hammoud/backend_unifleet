<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    | Safee Tracking REST Service (v2.2.0.0) — the platform DSCO also runs on.
    | One entry per partner company. Auth URL is derived per-provider as:
    |   {server_uri}/auth/realms/{realm}/protocol/openid-connect/token
    | To onboard a new company, add a new key under `providers` with its own
    | SAFEE_<NAME>_* env vars — the poller and services pick it up automatically.
    */
    'safee' => [
        'default'   => env('SAFEE_DEFAULT_PROVIDER', 'dsco'),

        /*
         * Per-vehicle telemetry endpoints the full poll should call.
         *
         * BOTH DISABLED 2026-11-01. Verified live against DSCO: get-fuel-data
         * and get-weight-data both answer HTTP 200 with
         * {"status":"success","result":null} for every vehicle, while
         * get-speed-data returns real data for the same vehicle in the same
         * second. The devices are Teltonika units, which only report fuel with
         * a fuel-level sensor or CAN adapter fitted - so the data does not
         * exist upstream rather than being lost in transit.
         *
         * Leaving them on cost ~125,000 HTTP requests a day that always return
         * nothing: the full poll runs every 5 minutes over 218 active vehicles,
         * and these were 2 of the 4 calls per vehicle. Half the poller's API
         * traffic, in one sequential PHP process that has previously exhausted
         * its memory limit before writing trips.
         *
         * Re-enable per endpoint by setting SAFEE_POLL_FUEL=true /
         * SAFEE_POLL_WEIGHT=true once the provider confirms sensors are fitted.
         * Nothing else needs changing - the write path, columns and the
         * low-fuel alert rule are all still in place and untouched.
         */
        'poll'      => [
            'fuel'   => env('SAFEE_POLL_FUEL', false),
            'weight' => env('SAFEE_POLL_WEIGHT', false),
        ],
        'providers' => [
            // Alrakeen — PAUSED 2026-10-29 (no longer working with them for now).
            // Credentials stay in .env; uncomment this block to resume polling.
            // Anything that enumerates providers (poller, safee:health, doctor,
            // diagnostics) picks it back up automatically.
            // 'alrakeen' => [
            //     'label'         => 'Alrakeen',
            //     'server_uri'    => env('SAFEE_ALRAKEEN_SERVER_URI', 'https://tk.alrakeen.sa'),
            //     'realm'         => env('SAFEE_ALRAKEEN_REALM', 'alrakeen'),
            //     'client_id'     => env('SAFEE_ALRAKEEN_CLIENT_ID', 'api'),
            //     'client_secret' => env('SAFEE_ALRAKEEN_CLIENT_SECRET'),
            //     'username'      => env('SAFEE_ALRAKEEN_USERNAME'),
            //     'password'      => env('SAFEE_ALRAKEEN_PASSWORD'),
            // ],

            // DSCO (Dimensions Systems) — the platform this project was first
            // built against. Retired 2026-07 in favour of Alrakeen, re-onboarded
            // 2026-10-24 as the second provider in place of saudiX. It is a Safee
            // deployment like the others, so only the realm/host differ.
            'dsco' => [
                'label'         => 'DSCO',
                'server_uri'    => env('SAFEE_DSCO_SERVER_URI', 'https://fms.dimensions-systems.com'),
                'realm'         => env('SAFEE_DSCO_REALM', 'dsco'),
                'client_id'     => env('SAFEE_DSCO_CLIENT_ID', 'api'),
                'client_secret' => env('SAFEE_DSCO_CLIENT_SECRET'),
                'username'      => env('SAFEE_DSCO_USERNAME'),
                'password'      => env('SAFEE_DSCO_PASSWORD'),
            ],
        ],
    ],

    'twilio' => [
        'sid'         => env('TWILIO_SID'),
        'auth_token'  => env('TWILIO_AUTH_TOKEN'),
        'from_number' => env('TWILIO_FROM_NUMBER'),
    ],

    'claude' => [
        'api_key' => env('CLAUDE_API_KEY'),
        'model'   => 'claude-sonnet-4-20250514',
    ],

    /*
    |--------------------------------------------------------------------------
    | Ops alerting (for US, not the client)
    |--------------------------------------------------------------------------
    |
    | Where `safee:health` sends "the pipeline is stale" notices.
    |
    | Email is the only channel for now, and it is IGNORED while MAIL_MAILER=log
    | (a log file nobody watches cannot page anyone). Until a real mailer is
    | configured, a dead pipeline is detected and logged but not delivered —
    | `unifleet:doctor` reports this as a failing `ops_alerts` check.
    |
    */
    'ops' => [
        'email' => env('OPS_ALERT_EMAIL'),

        // Optional shared secret for the full detail of GET /api/health. Without
        // it that endpoint stays terse (status + failing check names only).
        'health_token' => env('OPS_HEALTH_TOKEN'),
    ],

];
