<?php

return [
    /*
     | Server-side API key — keep this secret.
     | Used for server metrics and backend error reporting.
     */
    'api_key' => env('DOWNCTL_API_KEY'),

    /*
     | Public key — safe to expose in browser JS.
     | Used for frontend error reporting.
     */
    'public_key' => env('DOWNCTL_PUBLIC_KEY'),

    /*
     | When true (the default), transport errors are swallowed silently.
     | Set to false in local development to surface misconfiguration early.
     */
    'silent' => env('DOWNCTL_SILENT', true),

    /*
     | When true, error reports are dispatched via a queued job so they
     | never block the request cycle. Requires a working queue driver.
     */
    'queue' => env('DOWNCTL_QUEUE', false),

    /*
     | Minimum log level that triggers automatic exception capture.
     | Accepts: 'error', 'critical', 'alert', 'emergency'.
     | Set to null to disable automatic capture entirely.
     */
    'capture_level' => env('DOWNCTL_CAPTURE_LEVEL', 'error'),

    /*
     | Redact sensitive values from report context before sending to Downctl
     | or storing a queued report payload.
     */
    'redact_context' => env('DOWNCTL_REDACT_CONTEXT', true),

    /*
     | Replacement used for redacted context values.
     */
    'redacted_value' => '[REDACTED]',

    /*
     | Maximum depth used when normalizing nested context arrays and objects.
     */
    'max_context_depth' => 8,

    /*
     | How many missed runs before an alert is raised for a cron monitor.
     | Used by downctl:crons:sync to compute grace_time_in_minutes automatically.
     | Example: a job that runs hourly with missed_runs=2 gets a 120-minute grace period.
     */
    'cron_missed_runs_before_alert' => env('DOWNCTL_CRON_MISSED_RUNS', 2),

    /*
     | Where downctl:crons:sync writes the ping token file.
     | The DownctlServiceProvider reads this file to auto-apply ->cronMonitor() at runtime.
     | Defaults to storage/app/downctl-crons.json when null.
     */
    'cron_token_path' => env('DOWNCTL_CRON_TOKEN_PATH', null),

    /*
     | Context keys redacted case-insensitively at any nesting level.
     */
    'redacted_keys' => [
        'access_token',
        'api_key',
        'authorization',
        'client_secret',
        'cookie',
        'credentials',
        'csrf_token',
        'id_token',
        'jwt',
        'password',
        'password_confirmation',
        'private_key',
        'refresh_token',
        'secret',
        'set-cookie',
        'session',
        'session_id',
        'token',
        'x-api-key',
        'xsrf_token',
    ],
];
