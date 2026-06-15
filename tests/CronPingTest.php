<?php

declare(strict_types=1);

use Bluecapapps\Downctl\Config;
use Bluecapapps\Downctl\Exception\TransportException;
use Bluecapapps\Downctl\Http\TransportInterface;
use Bluecapapps\DownctlLaravel\DownctlClient;
use Bluecapapps\DownctlLaravel\Facades\Downctl;
use Bluecapapps\DownctlLaravel\Support\DownctlCronRegistry;

function makeCronTransport(int $status = 200, bool $throws = false): TransportInterface
{
    return new class($status, $throws) implements TransportInterface {
        public array $postCalls = [];
        public array $getCalls  = [];

        public function __construct(private int $status, private bool $throws) {}

        public function post(string $url, array $headers, array $body): int
        {
            if ($this->throws) {
                throw new TransportException('connection refused');
            }
            $this->postCalls[] = compact('url', 'body');

            return $this->status;
        }

        public function get(string $url, array $headers): int
        {
            if ($this->throws) {
                throw new TransportException('connection refused');
            }
            $this->getCalls[] = compact('url');

            return $this->status;
        }
    };
}

function makeClient(TransportInterface $transport): DownctlClient
{
    $client = new DownctlClient(new Config(apiKey: 'test-key'), $transport);
    app()->instance(DownctlClient::class, $client);
    app()->instance('downctl', $client);

    return $client;
}

test('pingCron sends a GET to the ping URL', function (): void {
    $transport = makeCronTransport();
    makeClient($transport);

    app(DownctlClient::class)->pingCron('test-token-abc');

    expect($transport->getCalls)->toHaveCount(1)
        ->and($transport->getCalls[0]['url'])->toBe('https://downctl.com/ping/cron/test-token-abc');
});

test('pingCron with metadata sends a POST with body', function (): void {
    $transport = makeCronTransport();
    makeClient($transport);

    app(DownctlClient::class)->pingCron('test-token-abc', ['runtime' => 1.23]);

    expect($transport->postCalls)->toHaveCount(1)
        ->and($transport->postCalls[0]['url'])->toBe('https://downctl.com/ping/cron/test-token-abc')
        ->and($transport->postCalls[0]['body'])->toBe(['runtime' => 1.23]);
});

test('pingCronStarted sends to /started', function (): void {
    $transport = makeCronTransport();
    makeClient($transport);

    app(DownctlClient::class)->pingCronStarted('my-token');

    expect($transport->getCalls[0]['url'])->toBe('https://downctl.com/ping/cron/my-token/started');
});

test('pingCronFinished sends to /finished', function (): void {
    $transport = makeCronTransport();
    makeClient($transport);

    app(DownctlClient::class)->pingCronFinished('my-token', ['runtime' => 5.0]);

    expect($transport->postCalls[0]['url'])->toBe('https://downctl.com/ping/cron/my-token/finished')
        ->and($transport->postCalls[0]['body'])->toBe(['runtime' => 5.0]);
});

test('pingCronFailed sends to /failed', function (): void {
    $transport = makeCronTransport();
    makeClient($transport);

    app(DownctlClient::class)->pingCronFailed('my-token', ['failure_message' => 'Something broke']);

    expect($transport->postCalls[0]['url'])->toBe('https://downctl.com/ping/cron/my-token/failed')
        ->and($transport->postCalls[0]['body']['failure_message'])->toBe('Something broke');
});

test('facade resolves cron ping methods', function (): void {
    $transport = makeCronTransport();
    makeClient($transport);

    Downctl::pingCron('facade-token');

    expect($transport->getCalls[0]['url'])->toBe('https://downctl.com/ping/cron/facade-token');
});

test('cron ping swallows exceptions in silent mode', function (): void {
    $transport = makeCronTransport(throws: true);
    makeClient($transport);

    expect(fn () => app(DownctlClient::class)->pingCron('any-token', ['runtime' => 1.0]))->not->toThrow(\Throwable::class);
});

test('downctl:crons:sync shows warning when no monitors configured', function (): void {
    $this->artisan('downctl:crons:sync')
        ->expectsOutputToContain('No scheduled tasks are configured with ->cronMonitor()')
        ->assertExitCode(0);
});

test('downctl:crons:sync lists registered monitors', function (): void {
    $registry = app(DownctlCronRegistry::class);

    $event = Mockery::mock(\Illuminate\Console\Scheduling\Event::class)
        ->makePartial()
        ->shouldReceive('getSummaryForDisplay')->andReturn('my:command')
        ->getMock();
    $event->expression = '0 * * * *';

    $registry->register('hash-1', 'ping-token-xyz', $event);

    $this->artisan('downctl:crons:sync')
        ->expectsOutputToContain('ping-token-xyz')
        ->assertExitCode(0);
});
