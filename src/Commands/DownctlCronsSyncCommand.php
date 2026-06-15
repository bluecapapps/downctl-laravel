<?php

declare(strict_types=1);

namespace Bluecapapps\DownctlLaravel\Commands;

use Bluecapapps\DownctlLaravel\DownctlClient;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;

class DownctlCronsSyncCommand extends Command
{
    protected $signature = 'downctl:crons:sync
        {--missed-runs= : Missed runs before alerting (overrides config)}
        {--dry-run : Show what would be synced without calling the API}';

    protected $description = 'Sync scheduled tasks with Downctl cron monitoring. Creates or updates monitors and writes ping tokens to storage.';

    public function handle(Schedule $schedule): int
    {
        if (blank(config('downctl.api_key'))) {
            $this->error('DOWNCTL_API_KEY is not set. Add it to your .env file.');

            return self::FAILURE;
        }

        /** @var DownctlClient $client */
        $client = app(DownctlClient::class);

        $missedRuns = (int) ($this->option('missed-runs') ?? config('downctl.cron_missed_runs_before_alert', 2));
        $monitors   = $this->collectMonitors($schedule, $missedRuns);

        if ($monitors === []) {
            $this->warn('No schedulable tasks found. Make sure your schedule defines Artisan commands or named closures.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info('Dry run — these monitors would be synced:');
            $this->table(
                ['Name', 'Schedule', 'Timezone', 'Missed Runs'],
                array_map(fn ($m) => [
                    $m['name'],
                    $m['cron_expression'] ?? 'Every '.$m['frequency_in_minutes'].' min',
                    $m['timezone'],
                    $m['missed_runs_before_alert'],
                ], $monitors),
            );

            return self::SUCCESS;
        }

        $this->info('Syncing '.count($monitors).' monitor'.((count($monitors) !== 1) ? 's' : '').' with Downctl...');

        try {
            $tokens = $client->syncCronMonitors($monitors);
        } catch (\Throwable $e) {
            $this->error('Sync failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->writeTokenFile($tokens);

        $rows = array_map(fn ($m) => [
            $m['name'],
            $m['cron_expression'] ?? 'Every '.$m['frequency_in_minutes'].' min',
            $m['missed_runs_before_alert'].' missed',
            $tokens[$m['name']] ?? '—',
        ], $monitors);

        $this->table(['Task', 'Schedule', 'Grace', 'Ping Token'], $rows);

        $path = $this->tokenPath();
        $this->info('✓ Tokens written to '.$path);
        $this->line('Add '.$path.' to .gitignore if it contains site-specific credentials.');

        return self::SUCCESS;
    }

    /** @return list<array{name: string, cron_expression?: string, frequency_in_minutes?: int, timezone: string, missed_runs_before_alert: int}> */
    private function collectMonitors(Schedule $schedule, int $missedRuns): array
    {
        $monitors  = [];
        $appTz     = config('app.timezone', 'UTC');

        foreach ($schedule->events() as $event) {
            // Skip closure-based events with no description
            if ($event instanceof CallbackEvent && blank($event->description)) {
                continue;
            }

            $name = $event->description ?: $event->getSummaryForDisplay();

            // Skip blank or internal downctl events
            if (blank($name) || str_starts_with($name, 'downctl:') || $name === 'cron-monitor-checks') {
                continue;
            }

            $timezone = filled($event->timezone) ? $event->timezone : $appTz;
            $entry    = ['name' => $name, 'timezone' => $timezone, 'missed_runs_before_alert' => $missedRuns];

            // Detect simple frequency patterns (*/N * * * * or * * * * *)
            $frequency = $this->expressionToFrequency($event->expression);

            if ($frequency !== null) {
                $entry['frequency_in_minutes'] = $frequency;
            } else {
                $entry['cron_expression'] = $event->expression;
            }

            $monitors[] = $entry;
        }

        return $monitors;
    }

    private function expressionToFrequency(string $expression): ?int
    {
        // Every minute: * * * * *
        if ($expression === '* * * * *') {
            return 1;
        }

        // Every N minutes: */N * * * *
        if (preg_match('/^\*\/(\d+) \* \* \* \*$/', $expression, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    /** @param array<string, string> $tokens */
    private function writeTokenFile(array $tokens): void
    {
        $path = $this->tokenPath();

        file_put_contents($path, json_encode([
            'version'   => 1,
            'synced_at' => now()->toIso8601String(),
            'monitors'  => $tokens,
        ], JSON_PRETTY_PRINT));
    }

    private function tokenPath(): string
    {
        $configured = config('downctl.cron_token_path');

        return filled($configured) ? $configured : storage_path('app/downctl-crons.json');
    }
}
