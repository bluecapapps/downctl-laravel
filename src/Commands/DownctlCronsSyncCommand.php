<?php

declare(strict_types=1);

namespace Bluecapapps\DownctlLaravel\Commands;

use Bluecapapps\Downctl\Config;
use Bluecapapps\DownctlLaravel\Support\DownctlCronRegistry;
use Illuminate\Console\Command;

class DownctlCronsSyncCommand extends Command
{
    protected $signature = 'downctl:crons:sync';

    protected $description = 'List all scheduled tasks configured with ->cronMonitor() and show their ping URLs.';

    public function handle(): int
    {
        $registry = app(DownctlCronRegistry::class);
        $monitors = $registry->all();

        if ($monitors === []) {
            $this->warn('No scheduled tasks are configured with ->cronMonitor().');
            $this->line('');
            $this->line('Add it to any schedule entry:');
            $this->line('  Schedule::command(\'your:command\')->cronMonitor(\'your-ping-token\');');

            return self::SUCCESS;
        }

        $baseUrl = rtrim(Config::DEFAULT_URL, '/').'/ping/cron/';

        $this->info('Cron monitors registered in the scheduler:');
        $this->line('');

        $rows = [];

        foreach ($monitors as $monitor) {
            $event = $monitor['event'];
            $token = $monitor['token'];

            $rows[] = [
                $event->getSummaryForDisplay(),
                $event->expression,
                $baseUrl.$token,
            ];
        }

        $this->table(
            headers: ['Task', 'Schedule', 'Ping URL'],
            rows: $rows,
        );

        return self::SUCCESS;
    }
}
