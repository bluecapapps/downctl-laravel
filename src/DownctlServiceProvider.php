<?php

declare(strict_types=1);

namespace Bluecapapps\DownctlLaravel;

use Bluecapapps\Downctl\Config;
use Bluecapapps\DownctlLaravel\Commands\DownctlCronsSyncCommand;
use Bluecapapps\DownctlLaravel\Commands\DownctlTestCommand;
use Bluecapapps\DownctlLaravel\Listeners\CaptureLoggedError;
use Bluecapapps\DownctlLaravel\Support\ContextRedactor;
use Bluecapapps\DownctlLaravel\Support\DownctlCronRegistry;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event as EventFacade;
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

        $this->app->singleton(DownctlCronRegistry::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/downctl.php' => config_path('downctl.php'),
            ], 'downctl-config');

            $this->commands([
                DownctlTestCommand::class,
                DownctlCronsSyncCommand::class,
            ]);
        }

        $this->registerLogListener();
        $this->registerScheduleMacro();
        $this->registerCronAutoApply();
    }

    private function registerCronAutoApply(): void
    {
        EventFacade::listen(CommandStarting::class, function (CommandStarting $event): void {
            if (! in_array($event->command, ['schedule:run', 'schedule:work'], strict: true)) {
                return;
            }

            $path = filled(config('downctl.cron_token_path'))
                ? config('downctl.cron_token_path')
                : storage_path('app/downctl-crons.json');

            if (! file_exists($path)) {
                return;
            }

            $stored  = json_decode(file_get_contents($path), associative: true);
            $tokens  = $stored['monitors'] ?? [];

            if ($tokens === []) {
                return;
            }

            /** @var \Illuminate\Console\Scheduling\Schedule $schedule */
            $schedule = $this->app->make(\Illuminate\Console\Scheduling\Schedule::class);
            $registry = $this->app->make(DownctlCronRegistry::class);

            $alreadyRegistered = array_map(
                fn ($entry) => spl_object_hash($entry['event']),
                $registry->all(),
            );

            foreach ($schedule->events() as $scheduleEvent) {
                if (in_array(spl_object_hash($scheduleEvent), $alreadyRegistered, strict: true)) {
                    continue;
                }

                $name = $scheduleEvent->description ?: $scheduleEvent->getSummaryForDisplay();

                if (isset($tokens[$name])) {
                    $scheduleEvent->cronMonitor($tokens[$name]);
                }
            }
        });
    }

    private function registerLogListener(): void
    {
        $captureLevel = config('downctl.capture_level');

        if (blank($captureLevel) || blank(config('downctl.api_key'))) {
            return;
        }

        EventFacade::listen(MessageLogged::class, CaptureLoggedError::class);
    }

    private function registerScheduleMacro(): void
    {
        Event::macro('cronMonitor', function (string $token): static {
            /** @var Event $this */
            app(DownctlCronRegistry::class)->register(spl_object_hash($this), $token, $this);

            $startTime = null;

            $this->before(function () use ($token, &$startTime): void {
                $startTime = microtime(true);
                app(DownctlClient::class)->pingCronStarted($token);
            });

            $this->onSuccess(function () use ($token, &$startTime): void {
                $metadata = $startTime !== null ? ['runtime' => round(microtime(true) - $startTime, 4)] : [];
                app(DownctlClient::class)->pingCronFinished($token, $metadata);
            });

            $this->onFailure(function () use ($token): void {
                app(DownctlClient::class)->pingCronFailed($token);
            });

            return $this;
        });
    }
}
