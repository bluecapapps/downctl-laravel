<?php

use Bluecapapps\DownctlLaravel\Facades\Downctl;
use Bluecapapps\DownctlLaravel\DownctlClient;

test('service provider binds the client as a singleton', function () {
    $a = app(DownctlClient::class);
    $b = app(DownctlClient::class);

    expect($a)->toBeInstanceOf(DownctlClient::class)
        ->and($a)->toBe($b);
});

test('downctl facade resolves to the client', function () {
    expect(Downctl::getFacadeRoot())->toBeInstanceOf(DownctlClient::class);
});

test('config is merged from package defaults', function () {
    expect(config('downctl.silent'))->toBeTrue()
        ->and(config('downctl.capture_level'))->toBe('error');
});

test('test command fails cleanly when api key is missing', function () {
    app('config')->set('downctl.api_key', null);

    $this->artisan('downctl:test')
        ->expectsOutput('DOWNCTL_API_KEY is not set. Add it to your .env file.')
        ->assertExitCode(1);
});
