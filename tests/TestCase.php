<?php

declare(strict_types=1);

namespace Bluecapapps\DownctlLaravel\Tests;

use Bluecapapps\DownctlLaravel\DownctlServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [DownctlServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('downctl.api_key', 'test-key-abc');
        $app['config']->set('downctl.silent', true);
        $app['config']->set('downctl.queue', false);
        $app['config']->set('downctl.capture_level', 'error');
    }
}
