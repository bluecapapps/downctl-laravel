<?php

declare(strict_types=1);

namespace Bluecapapps\DownctlLaravel;

use Bluecapapps\Downctl\Client;
use Bluecapapps\Downctl\Config;
use Bluecapapps\DownctlLaravel\Support\ContextRedactor;
use Illuminate\Support\Facades\Http;

class DownctlClient extends Client
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function captureException(\Throwable $e, string $level = 'error', array $context = []): void
    {
        parent::captureException($e, $level, $this->redactContext($context));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function report(
        string $message,
        string $level = 'error',
        ?string $stackTrace = null,
        ?string $url = null,
        array $context = [],
    ): void {
        parent::report($message, $level, $stackTrace, $url, $this->redactContext($context));
    }

    /**
     * Sync scheduled tasks with Downctl, creating or updating cron monitors.
     * Returns a map of monitor name → ping token.
     *
     * @param  list<array{name: string, cron_expression?: string|null, frequency_in_minutes?: int|null, timezone?: string, missed_runs_before_alert?: int}>  $monitors
     * @return array<string, string>
     */
    public function syncCronMonitors(array $monitors): array
    {
        $url = rtrim(Config::DEFAULT_URL, '/').'/api/v1/cron-monitors/sync';

        $response = Http::withHeaders(['X-Downctl-Key' => config('downctl.api_key')])
            ->post($url, ['monitors' => $monitors]);

        if ($response->failed()) {
            throw new \RuntimeException("Downctl cron sync failed with HTTP {$response->status()}.");
        }

        $tokens = [];

        foreach ($response->json('monitors', []) as $monitor) {
            $tokens[$monitor['name']] = $monitor['ping_token'];
        }

        return $tokens;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function redactContext(array $context): array
    {
        return app(ContextRedactor::class)->redact($context);
    }
}
