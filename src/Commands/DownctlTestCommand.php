<?php

declare(strict_types=1);

namespace Bluecapapps\DownctlLaravel\Commands;

use Bluecapapps\DownctlLaravel\DownctlClient;
use Illuminate\Console\Command;

class DownctlTestCommand extends Command
{
    private const API_URL = 'https://downctl.com';

    protected $signature = 'downctl:test';

    protected $description = 'Verify connectivity to the Downctl API using your configured credentials.';

    public function handle(): int
    {
        if (blank(config('downctl.api_key'))) {
            $this->error('DOWNCTL_API_KEY is not set. Add it to your .env file.');

            return self::FAILURE;
        }

        $this->info('Pinging '.self::API_URL.' ...');

        $client = app(DownctlClient::class);

        if (! $client->ping()) {
            $this->error('Health check failed. Check DOWNCTL_API_KEY.');

            return self::FAILURE;
        }

        $this->info('Sending a test error report ...');

        $client->report(
            message: 'downctl:test connectivity verified at '.now()->toIso8601String(),
            level: 'info',
        );

        $this->info('Connection OK. Downctl is configured correctly.');

        return self::SUCCESS;
    }
}
