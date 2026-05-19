<?php

declare(strict_types=1);

namespace Bluecapapps\DownctlLaravel;

use Bluecapapps\Downctl\Config;
use Bluecapapps\DownctlLaravel\Commands\DownctlTestCommand;
use Bluecapapps\DownctlLaravel\Listeners\CaptureLoggedError;
use Bluecapapps\DownctlLaravel\Support\ContextRedactor;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class DownctlServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/downctl.php', 'downctl');

        $this->app->singleton(DownctlClient::class, function (): DownctlClient {
            $config = new Config(
                apiKey: (string) config('downctl.api_key', ''),
                publicKey: config('downctl.public_key') ?: null,
                silent: (bool) config('downctl.silent', true),
                queue: (bool) config('downctl.queue', false),
            );

            return new DownctlClient($config);
        });

        // Bind the short alias used by the Facade.
        $this->app->alias(DownctlClient::class, 'downctl');
        $this->app->alias(DownctlClient::class, \Bluecapapps\Downctl\Client::class);

        $this->app->singleton(ContextRedactor::class, fn (): ContextRedactor => new ContextRedactor(
            keys: (array) config('downctl.redacted_keys', []),
            replacement: (string) config('downctl.redacted_value', '[REDACTED]'),
            enabled: (bool) config('downctl.redact_context', true),
            maxDepth: (int) config('downctl.max_context_depth', 8),
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/downctl.php' => config_path('downctl.php'),
            ], 'downctl-config');

            $this->commands([DownctlTestCommand::class]);
        }

        $this->registerLogListener();
    }

    private function registerLogListener(): void
    {
        $captureLevel = config('downctl.capture_level');

        if (blank($captureLevel) || blank(config('downctl.api_key'))) {
            return;
        }

        Event::listen(MessageLogged::class, CaptureLoggedError::class);
    }
}
