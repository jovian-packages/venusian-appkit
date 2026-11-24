<?php

declare(strict_types=1);

use Jovian\Toolkits\Appkit\AppkitAdapterDriver;
use Surface\Toolkits\ToolkitAdapters;

afterEach(function (): void {
    toolkitApp();
});

it('registers the toolkit without building its driver', function (): void {
    bootCore();

    expect(ToolkitAdapters::adapters())->toBe([]);
});

it('merges its config with the package defaults', function (): void {
    bootCore();

    expect(config('appkit'))->toBeArray()->not->toBeEmpty();
});

it('builds its driver when toolkits.default selects it, with nothing native started', function (): void {
    bootCore();

    $driver = ToolkitAdapters::driver();

    expect($driver)->toBeInstanceOf(AppkitAdapterDriver::class)
        ->and(ToolkitAdapters::driver('appkit'))->toBe($driver)
        ->and($driver->session())->toBeNull();
});
