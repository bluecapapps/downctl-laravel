<?php

declare(strict_types=1);

namespace Bluecapapps\DownctlLaravel\Facades;

use Bluecapapps\Downctl\Payload\MetricsPayload;
use Illuminate\Support\Facades\Facade;

/**
 * @method static void captureException(\Throwable $e, string $level = 'error', array $context = [])
 * @method static void report(string $message, string $level = 'error', ?string $stackTrace = null, ?string $url = null, array $context = [])
 * @method static void reportMetrics(MetricsPayload $metrics)
 * @method static bool ping()
 * @method static void pingCron(string $token, array $metadata = [])
 * @method static void pingCronStarted(string $token, array $metadata = [])
 * @method static void pingCronFinished(string $token, array $metadata = [])
 * @method static void pingCronFailed(string $token, array $metadata = [])
 *
 * @see \Bluecapapps\DownctlLaravel\DownctlClient
 */
class Downctl extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'downctl';
    }
}
