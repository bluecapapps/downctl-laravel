# bluecapapps/downctl-laravel

Laravel integration for the Downctl error and metrics reporting API. It adds automatic exception capture, a Facade, queue support, and an Artisan health-check command.

## Requirements

- PHP 8.2+
- Laravel 10, 11, 12, or 13
- `curl` extension

## Installation

```bash
composer require bluecapapps/downctl-laravel
```

The service provider and `Downctl` facade are registered automatically via Laravel's package auto-discovery.

### Publish the config file

```bash
php artisan vendor:publish --tag=downctl-config
```

This creates `config/downctl.php` in your application.

## Configuration

Add your server-side API key to `.env`:

```ini
DOWNCTL_API_KEY=your-secret-api-key
```

Downctl reports are always sent to `https://downctl.com`; there is no URL setting to configure.

| Key | Env variable | Default | Description |
|---|---|---|---|
| `api_key` | `DOWNCTL_API_KEY` | `null` | Server-side secret key |
| `public_key` | `DOWNCTL_PUBLIC_KEY` | `null` | Public key for frontend JS use |
| `silent` | `DOWNCTL_SILENT` | `true` | Swallow transport errors |
| `queue` | `DOWNCTL_QUEUE` | `false` | Report asynchronously via a queue job |
| `capture_level` | `DOWNCTL_CAPTURE_LEVEL` | `error` | Minimum log level to auto-capture |
| `redact_context` | `DOWNCTL_REDACT_CONTEXT` | `true` | Redact sensitive context values |
| `redacted_value` | - | `[REDACTED]` | Replacement for redacted values |
| `max_context_depth` | - | `8` | Maximum nested context depth |
| `redacted_keys` | - | see config | Context keys redacted at any nesting level |

If `DOWNCTL_API_KEY` is missing, automatic capture is skipped so a newly installed application will not crash before credentials are configured.

### Verify your setup

```bash
php artisan downctl:test
```

This pings Downctl and sends a test `info` report.

## Usage

### Facade

```php
use Bluecapapps\DownctlLaravel\Facades\Downctl;

Downctl::captureException($e);

Downctl::report('Stripe webhook signature invalid', level: 'error');

Downctl::captureException($e, level: 'warning', context: [
    'user_id' => auth()->id(),
    'plan' => $user->plan,
]);
```

### Dependency injection

The underlying client is bound as a singleton and can be injected anywhere:

```php
use Bluecapapps\DownctlLaravel\DownctlClient;

class PaymentService
{
    public function __construct(private DownctlClient $downctl) {}

    public function charge(Order $order): void
    {
        try {
            $this->gateway->charge($order);
        } catch (\Throwable $e) {
            $this->downctl->captureException($e, context: ['order_id' => $order->id]);
            throw $e;
        }
    }
}
```

## Automatic Capture

The package listens to Laravel's `MessageLogged` event and forwards any log entry at or above `capture_level` to Downctl. No changes to your exception handler are required.

Laravel uses the full PSR-3 scale (`debug`, `info`, `notice`, `warning`, `error`, `critical`, `alert`, `emergency`). Downctl accepts three levels, so the listener normalizes them:

| Laravel log level | Reported as |
|---|---|
| `error`, `critical`, `alert`, `emergency` | `error` |
| `warning` | `warning` |
| `info`, `notice`, `debug` | `info` |

Capture only `error` and above:

```ini
DOWNCTL_CAPTURE_LEVEL=error
```

Capture `warning` and above:

```ini
DOWNCTL_CAPTURE_LEVEL=warning
```

Disable automatic capture entirely:

```ini
DOWNCTL_CAPTURE_LEVEL=
```

With capture disabled you can still call `Downctl::captureException()` manually.

### Context redaction

Report context is redacted by default before it is sent to Downctl. When `DOWNCTL_QUEUE=true`, context is redacted before the queued job is stored, so queue payloads do not contain the original sensitive values.

Downctl assumes encrypted transport and trusted source and destination systems, so the default redaction policy preserves troubleshooting detail such as user IDs, order IDs, route names, statuses, and email addresses. It redacts high-confidence secret keys such as `password`, `access_token`, `refresh_token`, `api_key`, `authorization`, `cookie`, `client_secret`, `session_id`, and `private_key`. Matching is case-insensitive and applies at any nesting level. You can customize the list in `config/downctl.php`.

Context objects are always normalized before sending, even when `redact_context` is disabled. Laravel `Arrayable` objects and `JsonSerializable` objects are converted and redacted recursively, `Stringable` objects are sent as strings, and other objects are summarized by class name. Deeply nested context is bounded by `max_context_depth`.

Log messages and stack traces are sent as-is. Avoid placing secrets directly in log message text or exception messages.

## Queue Support

```ini
DOWNCTL_QUEUE=true
```

When enabled, the `CaptureLoggedError` listener dispatches a `SendDownctlReport` job instead of making a direct HTTP call. The job retries 3 times with a 30-second backoff.

You must have a working queue driver configured. The `sync` driver sends reports immediately, the same as `DOWNCTL_QUEUE=false`.

## Cron Monitoring

Cron monitoring lets Downctl alert you when a scheduled job misses its expected window, takes too long, or exits with an error. Each monitor has a unique ping token; your job hits that URL to prove it ran.

### 1. Create a monitor in Downctl

Open your site in Downctl, navigate to **Cron monitors**, and add a monitor. Set the schedule (cron expression or a simple minute frequency) and a grace period. Copy the ping token shown on the monitor card.

Monitor ping tokens are credentials. Store them in `.env` rather than writing them directly in `routes/console.php`:

```ini
DOWNCTL_REPORTS_DAILY_MONITOR_TOKEN=a1b2c3d4e5f6...
DOWNCTL_CACHE_PRUNE_MONITOR_TOKEN=x7y8z9...
```

Expose the values through a Laravel config file, such as `config/downctl.php`, so they continue to work when configuration is cached:

```php
'monitors' => [
    'reports_daily' => env('DOWNCTL_REPORTS_DAILY_MONITOR_TOKEN'),
    'cache_prune' => env('DOWNCTL_CACHE_PRUNE_MONITOR_TOKEN'),
],
```

### 2. Add the Schedule macro

In your `routes/console.php`, chain `->cronMonitor()` onto each scheduled task using the configured token:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('reports:daily')
    ->dailyAt('06:00')
    ->cronMonitor(config('downctl.monitors.reports_daily'));

Schedule::command('cache:prune')
    ->hourly()
    ->cronMonitor(config('downctl.monitors.cache_prune'));
```

The macro automatically:

- Pings `/started` immediately before the task runs.
- Pings `/finished` (with the measured runtime in seconds) after a successful run.
- Pings `/failed` if the command exits with a non-zero code.

No other changes are required. Downctl updates the monitor status in real time and opens an alert incident if a ping does not arrive within the configured grace period.

### 3. Verify your configuration

```bash
php artisan downctl:crons:sync
```

This lists every scheduled task that has `->cronMonitor()` applied and shows the ping URL it will call:

```
+---------------------+-------------+----------------------------------------------------------+
| Task                | Schedule    | Ping URL                                                 |
+---------------------+-------------+----------------------------------------------------------+
| reports:daily       | 0 6 * * *   | https://downctl.com/ping/cron/a1b2c3d4e5f6...           |
| cache:prune         | 0 * * * *   | https://downctl.com/ping/cron/x7y8z9...                 |
+---------------------+-------------+----------------------------------------------------------+
```

### Manual pinging

For jobs that run outside the Laravel scheduler (queue workers, shell scripts, external cron jobs), ping the URLs directly using the Facade or client:

```php
use Bluecapapps\DownctlLaravel\Facades\Downctl;

// Simple heartbeat — marks the monitor as healthy
Downctl::pingCron('your-token');

// Lifecycle pings with optional metadata
Downctl::pingCronStarted('your-token');

Downctl::pingCronFinished('your-token', [
    'runtime'     => 12.4,  // seconds
    'memory'      => 52428800,  // bytes
]);

Downctl::pingCronFailed('your-token', [
    'exit_code'       => 1,
    'failure_message' => 'Connection to database timed out',
]);
```

Ping URLs do not require the API key; the token in the URL is the credential. You can also call them with a plain `curl` from a shell script:

```bash
# Simple ping
curl -s "https://downctl.com/ping/cron/your-token"

# With lifecycle and metadata
curl -s "https://downctl.com/ping/cron/your-token/started"
curl -s -X POST "https://downctl.com/ping/cron/your-token/finished" \
     -H "Content-Type: application/json" \
     -d '{"runtime": 4.2, "exit_code": 0}'
curl -s -X POST "https://downctl.com/ping/cron/your-token/failed" \
     -H "Content-Type: application/json" \
     -d '{"exit_code": 1, "failure_message": "Unexpected error"}'
```

### Accepted ping metadata

| Field | Type | Description |
|---|---|---|
| `runtime` | float | Job duration in seconds |
| `memory` | int | Peak memory usage in bytes |
| `exit_code` | int | Process exit code (non-zero promotes a `/finished` ping to `/failed`) |
| `failure_message` | string | Human-readable failure reason (max 255 chars) |

All fields are optional. A non-zero `exit_code` sent to any endpoint is always treated as a failure.

## Troubleshooting

### `downctl:test` reports "Health check failed"

- Confirm `DOWNCTL_API_KEY` is set.
- Check that the app can reach `https://downctl.com/api/v1/health`.
- Set `DOWNCTL_SILENT=false` locally if you want transport failures to throw.

### Errors are not appearing in Downctl

1. Run `php artisan downctl:test`.
2. Check `DOWNCTL_CAPTURE_LEVEL`.
3. Make sure `DOWNCTL_API_KEY` is set.
4. If `DOWNCTL_QUEUE=true`, make sure your queue worker is running.

### Reports appear in Downctl but are missing stack traces

Laravel only includes the `exception` key in the log context when you use `Log::error('message', ['exception' => $e])` or when the exception handler logs it. If you call `Log::error('message')` without an exception object, no stack trace is available. Use `Downctl::captureException($e)` directly when you have a `Throwable`.

### Auto-discovery is not registering the provider

Check that `dont-discover` in your `composer.json` does not include `bluecapapps/downctl-laravel`. If you've disabled auto-discovery project-wide, register the provider manually:

```php
// bootstrap/providers.php (Laravel 11+)
return [
    Bluecapapps\DownctlLaravel\DownctlServiceProvider::class,
];
```

```php
// config/app.php (Laravel 10)
'providers' => [
    Bluecapapps\DownctlLaravel\DownctlServiceProvider::class,
],
```

## Running the tests

```bash
composer install
./vendor/bin/pest
```
