<?php

declare(strict_types=1);

use Jovian\Toolkits\Appkit\AppkitSession;
use Jovian\Toolkits\Appkit\Appkit;
use Surface\Contracts\Toolkits\Styling\ColorScheme;

it('connects one session for the driver', function (): void {
    $session = session();

    expect($session)->toBeInstanceOf(AppkitSession::class)
        ->and($session->connected())->toBeTrue()
        ->and($session->application())->toBeInstanceOf(Appkit::class)
        ->and(driver()->connect())->toBe($session);
});

it('makes the shared NSApplication a regular app while connected', function (): void {
    $application = session()->application()->get();

    expect($application)->toBe(NSApplication::sharedApplication())
        ->and($application->activationPolicy())->toBe(NSApplicationActivationPolicy::REGULAR);

    session()->disconnect();
    expect($application->activationPolicy())->toBe(NSApplicationActivationPolicy::PROHIBITED);

    session()->connect();
    expect($application->activationPolicy())->toBe(NSApplicationActivationPolicy::REGULAR);
});

it('reconnects to the same toolkit library', function (): void {
    $toolkit = session()->application();

    session()->disconnect();
    expect(session()->connected())->toBeTrue()
        ->and(session()->application())->toBe($toolkit);
});

it('draws in the colour scheme it is given: the application\'s own appearance, the system\'s for none', function (): void {
    $apply = new ReflectionMethod(AppkitSession::class, 'applyColorScheme');
    $application = session()->application()->get();

    $apply->invoke(session(), ColorScheme::DARK);
    expect($application->appearance()->name())->toBe(NSAppearanceNameDarkAqua);

    $apply->invoke(session(), ColorScheme::LIGHT);
    expect($application->appearance()->name())->toBe(NSAppearanceNameAqua);

    $apply->invoke(session(), ColorScheme::SYSTEM);
    expect($application->appearance())->toBeNull();
});
