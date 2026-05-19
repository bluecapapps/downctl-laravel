<?php

declare(strict_types=1);

namespace Bluecapapps\DownctlLaravel\Jobs;

use Bluecapapps\DownctlLaravel\DownctlClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class SendDownctlReport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        private readonly string $message,
        private readonly string $level,
        private readonly ?string $stackTrace,
        private readonly array $context,
    ) {}

    public function handle(DownctlClient $client): void
    {
        $client->report(
            message: $this->message,
            level: $this->level,
            stackTrace: $this->stackTrace,
            context: $this->context,
        );
    }
}
