<?php

declare(strict_types=1);

namespace Bluecapapps\DownctlLaravel;

use Bluecapapps\Downctl\Client;
use Bluecapapps\DownctlLaravel\Support\ContextRedactor;

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
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function redactContext(array $context): array
    {
        return app(ContextRedactor::class)->redact($context);
    }
}
