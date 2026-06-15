<?php

declare(strict_types=1);

namespace Bluecapapps\DownctlLaravel\Support;

use Illuminate\Console\Scheduling\Event;

class DownctlCronRegistry
{
    /** @var array<string, array{token: string, event: Event}> */
    private array $monitors = [];

    public function register(string $hash, string $token, Event $event): void
    {
        $this->monitors[$hash] = ['token' => $token, 'event' => $event];
    }

    /** @return list<array{token: string, event: Event}> */
    public function all(): array
    {
        return array_values($this->monitors);
    }
}
