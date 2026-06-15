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

test('downctl:crons:sync warns when no api key is set', function (): void {
    config(['downctl.api_key' => null]);

    $this->artisan('downctl:crons:sync')
        ->expectsOutputToContain('DOWNCTL_API_KEY is not set')
        ->assertExitCode(1);
});

test('downctl:crons:sync warns when schedule has no eligible tasks', function (): void {
    $this->artisan('downctl:crons:sync')
        ->expectsOutputToContain('No schedulable tasks found')
        ->assertExitCode(0);
});

test('downctl:crons:sync --dry-run shows events without calling the api', function (): void {
    Http::fake();

    app(\Illuminate\Console\Scheduling\Schedule::class)
        ->command('inspire')
        ->hourly()
        ->description('Send daily inspiration');

    $this->artisan('downctl:crons:sync --dry-run')
        ->expectsOutputToContain('Send daily inspiration')
        ->assertExitCode(0);

    Http::assertNothingSent();
});

test('downctl:crons:sync calls api and writes token file', function (): void {
    Http::fake([
        '*/cron-monitors/sync' => Http::response([
            'monitors' => [
                ['name' => 'Nightly cleanup', 'ping_token' => 'tok-abc', 'ping_url' => 'https://downctl.com/ping/cron/tok-abc', 'grace_time_in_minutes' => 120, 'missed_runs_before_alert' => 2],
            ],
        ]),
    ]);

    $path = sys_get_temp_dir().'/downctl-crons-test.json';
    config(['downctl.cron_token_path' => $path]);

    app(\Illuminate\Console\Scheduling\Schedule::class)
        ->command('inspire')
        ->hourly()
        ->description('Nightly cleanup');

    $this->artisan('downctl:crons:sync')
        ->expectsOutputToContain('tok-abc')
        ->assertExitCode(0);

    expect(file_exists($path))->toBeTrue();
    $stored = json_decode(file_get_contents($path), true);
    expect($stored['monitors']['Nightly cleanup'])->toBe('tok-abc');

    @unlink($path);
});
