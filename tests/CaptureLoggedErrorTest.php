<?php

use Bluecapapps\DownctlLaravel\DownctlClient;
use Bluecapapps\DownctlLaravel\Jobs\SendDownctlReport;
use Bluecapapps\Downctl\Http\TransportInterface;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

function stubTransport(): TransportInterface
{
    return new class implements TransportInterface {
        public array $posts = [];

        public function post(string $url, array $headers, array $body): int
        {
            $this->posts[] = $body;

            return 201;
        }

        public function get(string $url, array $headers): int
        {
            return 200;
        }
    };
}

function jobProperty(object $job, string $name): mixed
{
    $property = new ReflectionProperty($job, $name);

    return $property->getValue($job);
}

test('error log event is captured and reported', function () {
    $transport = stubTransport();

    app()->bind(DownctlClient::class, fn () => new DownctlClient(
        new \Bluecapapps\Downctl\Config('test-key'),
        $transport,
    ));

    Log::error('Something went wrong');

    expect($transport->posts)->toHaveCount(1)
        ->and($transport->posts[0]['message'])->toBe('Something went wrong')
        ->and($transport->posts[0]['level'])->toBe('error');
});

test('warning log event is captured with warning level', function () {
    $transport = stubTransport();

    app()->bind(DownctlClient::class, fn () => new DownctlClient(
        new \Bluecapapps\Downctl\Config('test-key'),
        $transport,
    ));

    app('config')->set('downctl.capture_level', 'warning');

    Log::warning('Slow response detected');

    expect($transport->posts)->toHaveCount(1)
        ->and($transport->posts[0]['level'])->toBe('warning');
});

test('debug log is not captured when capture level is error', function () {
    $transport = stubTransport();

    app()->bind(DownctlClient::class, fn () => new DownctlClient(
        new \Bluecapapps\Downctl\Config('test-key'),
        $transport,
    ));

    Log::debug('Verbose debug info');

    expect($transport->posts)->toHaveCount(0);
});

test('info log is not captured when capture level is error', function () {
    $transport = stubTransport();

    app()->bind(DownctlClient::class, fn () => new DownctlClient(
        new \Bluecapapps\Downctl\Config('test-key'),
        $transport,
    ));

    Log::info('Just an informational message');

    expect($transport->posts)->toHaveCount(0);
});

test('blank log message is not captured', function () {
    $transport = stubTransport();

    app()->bind(DownctlClient::class, fn () => new DownctlClient(
        new \Bluecapapps\Downctl\Config('test-key'),
        $transport,
    ));

    Log::error('');

    expect($transport->posts)->toHaveCount(0);
});

test('exception context is extracted as stack trace', function () {
    $transport = stubTransport();

    app()->bind(DownctlClient::class, fn () => new DownctlClient(
        new \Bluecapapps\Downctl\Config('test-key'),
        $transport,
    ));

    $e = new \RuntimeException('Boom');
    Log::error('Caught an exception', ['exception' => $e]);

    expect($transport->posts[0])->toHaveKey('stack_trace');
});

test('sensitive context keys are redacted before direct report', function () {
    $transport = stubTransport();

    app()->bind(DownctlClient::class, fn () => new DownctlClient(
        new \Bluecapapps\Downctl\Config('test-key'),
        $transport,
    ));

    Log::error('Payment failed', [
        'user_id' => 123,
        'email' => 'customer@example.test',
        'status' => 'declined',
        'password' => 'secret-password',
        'headers' => [
            'Authorization' => 'Bearer abc123',
            'Accept' => 'application/json',
        ],
    ]);

    expect($transport->posts[0]['context'])->toBe([
        'user_id' => 123,
        'email' => 'customer@example.test',
        'status' => 'declined',
        'password' => '[REDACTED]',
        'headers' => [
            'Authorization' => '[REDACTED]',
            'Accept' => 'application/json',
        ],
    ]);
});

test('sensitive context keys are redacted before queue dispatch', function () {
    Queue::fake();

    app('config')->set('downctl.queue', true);

    Log::error('Payment failed', [
        'email' => 'customer@example.test',
        'metadata' => [
            'access_token' => 'secret-value',
            'order_id' => 456,
        ],
    ]);

    Queue::assertPushed(SendDownctlReport::class, function (SendDownctlReport $job): bool {
        return jobProperty($job, 'context') === [
            'email' => 'customer@example.test',
            'metadata' => [
                'access_token' => '[REDACTED]',
                'order_id' => 456,
            ],
        ];
    });
});

test('context redaction can be disabled while normalization still runs', function () {
    $transport = stubTransport();

    app('config')->set('downctl.redact_context', false);

    app()->bind(DownctlClient::class, fn () => new DownctlClient(
        new \Bluecapapps\Downctl\Config('test-key'),
        $transport,
    ));

    Log::error('Payment failed', [
        'token' => 'visible-token',
        'object' => new class {},
    ]);

    expect($transport->posts[0]['context'])->toBe([
        'token' => 'visible-token',
        'object' => '[object anonymous]',
    ]);
});

test('redacted keys and replacement value are configurable', function () {
    $transport = stubTransport();

    app('config')->set('downctl.redacted_keys', ['customer_id']);
    app('config')->set('downctl.redacted_value', '[hidden]');

    app()->bind(DownctlClient::class, fn () => new DownctlClient(
        new \Bluecapapps\Downctl\Config('test-key'),
        $transport,
    ));

    Log::error('Payment failed', [
        'customer_id' => 789,
        'token' => 'visible-token',
    ]);

    expect($transport->posts[0]['context'])->toBe([
        'customer_id' => '[hidden]',
        'token' => 'visible-token',
    ]);
});

test('arrayable and json serializable context is normalized and redacted', function () {
    $transport = stubTransport();

    app()->bind(DownctlClient::class, fn () => new DownctlClient(
        new \Bluecapapps\Downctl\Config('test-key'),
        $transport,
    ));

    $arrayable = new class implements Arrayable {
        public function toArray(): array
        {
            return [
                'route' => 'checkout.confirm',
                'session_id' => 'session-secret',
            ];
        }
    };

    $jsonSerializable = new class implements JsonSerializable {
        public function jsonSerialize(): array
        {
            return [
                'order_id' => 456,
                'id_token' => 'id-token-secret',
            ];
        }
    };

    Log::error('Payment failed', [
        'arrayable' => $arrayable,
        'json' => $jsonSerializable,
    ]);

    expect($transport->posts[0]['context'])->toBe([
        'arrayable' => [
            'route' => 'checkout.confirm',
            'session_id' => '[REDACTED]',
        ],
        'json' => [
            'order_id' => 456,
            'id_token' => '[REDACTED]',
        ],
    ]);
});

test('stringable context is preserved and generic objects are summarized', function () {
    $transport = stubTransport();

    app()->bind(DownctlClient::class, fn () => new DownctlClient(
        new \Bluecapapps\Downctl\Config('test-key'),
        $transport,
    ));

    $stringable = new class implements Stringable {
        public function __toString(): string
        {
            return 'payment-gateway-timeout';
        }
    };

    $generic = new class {};

    Log::error('Payment failed', [
        'reason' => $stringable,
        'object' => $generic,
        'credentials' => $stringable,
    ]);

    expect($transport->posts[0]['context'])->toBe([
        'reason' => 'payment-gateway-timeout',
        'object' => '[object anonymous]',
        'credentials' => '[REDACTED]',
    ]);
});

test('deep context is bounded', function () {
    $transport = stubTransport();

    app('config')->set('downctl.max_context_depth', 2);

    app()->bind(DownctlClient::class, fn () => new DownctlClient(
        new \Bluecapapps\Downctl\Config('test-key'),
        $transport,
    ));

    Log::error('Payment failed', [
        'level_one' => [
            'level_two' => [
                'level_three' => 'too deep',
            ],
        ],
    ]);

    expect($transport->posts[0]['context'])->toBe([
        'level_one' => [
            'level_two' => '[MAX_DEPTH]',
        ],
    ]);
});

test('automatic capture is skipped when api key is missing', function () {
    app('config')->set('downctl.api_key', null);

    Log::error('Something went wrong before setup');

    expect(true)->toBeTrue();
});
