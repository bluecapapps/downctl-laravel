<?php

declare(strict_types=1);

namespace Bluecapapps\DownctlLaravel\Listeners;

use Bluecapapps\DownctlLaravel\DownctlClient;
use Bluecapapps\DownctlLaravel\Jobs\SendDownctlReport;
use Bluecapapps\DownctlLaravel\Support\ContextRedactor;
use Illuminate\Log\Events\MessageLogged;

class CaptureLoggedError
{
    private const LEVEL_ORDER = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];

    public function __construct(private readonly ContextRedactor $redactor) {}

    public function handle(MessageLogged $event): void
    {
        $captureLevel = (string) config('downctl.capture_level', 'error');

        if (blank($event->message) || blank(config('downctl.api_key')) || ! $this->meetsThreshold($event->level, $captureLevel)) {
            return;
        }

        // Normalise level to the three values the API accepts.
        $level = match (true) {
            in_array($event->level, ['warning'], true) => 'warning',
            in_array($event->level, ['info', 'notice', 'debug'], true) => 'info',
            default => 'error',
        };

        $throwable  = $event->context['exception'] ?? null;
        $stackTrace = $throwable instanceof \Throwable ? $throwable->getTraceAsString() : null;

        $context = $this->redactor->redact(
            array_filter($event->context, fn ($v, $k) => $k !== 'exception', ARRAY_FILTER_USE_BOTH)
        );

        if ((bool) config('downctl.queue', false)) {
            SendDownctlReport::dispatch($event->message, $level, $stackTrace, $context);

            return;
        }

        app(DownctlClient::class)->report(
            message: $event->message,
            level: $level,
            stackTrace: $stackTrace,
            context: $context,
        );
    }

    private function meetsThreshold(string $eventLevel, string $minLevel): bool
    {
        $eventIndex = array_search($eventLevel, self::LEVEL_ORDER, true);
        $minIndex   = array_search($minLevel, self::LEVEL_ORDER, true);

        if ($eventIndex === false || $minIndex === false) {
            return false;
        }

        return $eventIndex >= $minIndex;
    }
}
