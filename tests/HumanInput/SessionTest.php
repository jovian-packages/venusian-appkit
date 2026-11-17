<?php

declare(strict_types=1);

require_once __DIR__.'/Support.php';

use Jovian\Toolkits\Appkit\AppkitSession;
use Jovian\Toolkits\Appkit\Services\AppkitAlertEscape;
use Jovian\Toolkits\Appkit\Windows\AppkitFocus;
use Surface\Contracts\Toolkits\Mail\Views\FocusGained;
use Surface\Contracts\Toolkits\Mail\Views\FocusLost;
use Surface\Toolkits\ToolkitSession;
use Surface\Toolkits\Toolkits;

/*
 * What the AppKit session's pump does around each event it takes: a filter can keep an event from
 * AppKit (the alert's Esc), and what runs after each event runs once AppKit is done with it (the
 * focus tracker). Events are posted to the application's queue and taken by the real pump.
 */

afterEach(function (): void {
    hiCloseWindows();
});

function hiSession(): AppkitSession
{
    /** @var AppkitSession */
    return Toolkits::bridge('appkit')->session();
}

/** Takes what the queue holds, so the events a test posts are the only ones the next pump takes. */
function hiDrain(AppkitSession $session): void
{
    $session->pump(0);
}

/** The mail the session holds for the loop, taken. */
function hiSessionMail(AppkitSession $session): array
{
    return Closure::bind(function (): array {
        [$mail, $this->outbox] = [$this->outbox, []];

        return $mail;
    }, $session, ToolkitSession::class)();
}

function hiWake(): NSEvent
{
    return NSEvent::otherEventWithTypeLocationModifierFlagsTimestampWindowNumberContextSubtypeData1Data2(
        NSEventType::APPLICATION_DEFINED, new NSPoint(0.0, 0.0), 0, 0.0, 0, null, 0, 0, 0,
    );
}

it('withholds from AppKit an event a filter handled, and runs what follows each event either way', function (): void {
    hiInput();
    [$window] = hiWindow();
    $session = hiSession();
    $app = NSApplication::sharedApplication();
    // the system can post events of its own meanwhile (a touch, DIRECT_TOUCH): every event the pump takes is counted
    $runs = new ArrayObject(['filtered' => [], 'appkit' => [], 'after' => 0, 'events' => 0]);
    $monitor = NSEvent::addLocalMonitorForEventsMatchingMaskHandler(NSEventMask::KEY_DOWN->value, function (NSEvent $event) use ($runs): NSEvent {
        $runs['appkit'] = [...$runs['appkit'], $event->keyCode()];

        return $event;
    });
    $owner = new stdClass();
    hiDrain($session);

    $session->withholdWhere($owner, function (NSEvent $event) use ($runs): bool {
        $runs['events']++;
        if ($event->type() !== NSEventType::KEY_DOWN) {
            return false;
        }
        $runs['filtered'] = [...$runs['filtered'], $event->keyCode()];

        return $event->keyCode() === 53;
    });
    $session->afterDispatch($owner, function () use ($runs): void {
        $runs['after']++;
    });
    $app->postEventAtStart(hiKey($window, 53, true, "\e"), false);
    $app->postEventAtStart(hiKey($window, 0x00, true, 'a'), false);
    $taken = $session->pump(0);
    $with = [$runs['filtered'], $runs['appkit']];
    [$events, $after] = [$runs['events'], $runs['after']];

    $session->withholdWhere($owner, null);
    $session->afterDispatch($owner, null);
    $app->postEventAtStart(hiKey($window, 53, true, "\e"), false);
    $session->pump(0);
    NSEvent::removeMonitor($monitor);

    expect($with)->toBe([[53, 0], [0]])
        ->and($events)->toBeGreaterThanOrEqual(2)
        ->and($after)->toBe($events)
        ->and($taken)->toBe($events)
        ->and($runs['appkit'])->toBe([0, 53])
        ->and($runs['after'])->toBe($after);
});

it('answers Esc at its alert, keeping the key from AppKit, and only there', function (): void {
    hiInput();
    [$window] = hiWindow('alert');
    [$other] = hiWindow('other');
    $session = hiSession();
    $escaped = new ArrayObject(['count' => 0]);
    $escape = new AppkitAlertEscape($session, $window->native(), function () use ($escaped): void {
        $escaped['count']++;
    });

    $answers = [
        'esc at the alert' => $escape->escapes(hiKey($window, 53, true, "\e")),
        'esc released' => $escape->escapes(hiKey($window, 53, false, "\e")),
        'return at the alert' => $escape->escapes(hiKey($window, 36, true, "\r")),
        'esc at another window' => $escape->escapes(hiKey($other, 53, true, "\e")),
    ];
    $escape->detach();
    $after = $escape->escapes(hiKey($window, 53, true, "\e"));

    expect($answers)->toBe(['esc at the alert' => true, 'esc released' => false, 'return at the alert' => false, 'esc at another window' => false])
        ->and($escaped['count'])->toBe(1)
        ->and([$after, $escape->watching()])->toBe([false, false]);
});

it('takes Esc at its alert through the session\'s pump, so AppKit never sees it', function (): void {
    hiInput();
    [$window] = hiWindow();
    $session = hiSession();
    $app = NSApplication::sharedApplication();
    $runs = new ArrayObject(['escaped' => 0, 'appkit' => 0]);
    $monitor = NSEvent::addLocalMonitorForEventsMatchingMaskHandler(NSEventMask::KEY_DOWN->value, function (NSEvent $event) use ($runs): NSEvent {
        $runs['appkit']++;

        return $event;
    });
    hiDrain($session);
    $escape = new AppkitAlertEscape($session, $window->native(), function () use ($runs): void {
        $runs['escaped']++;
    });

    $app->postEventAtStart(hiKey($window, 53, true, "\e"), false);
    $session->pump(0);
    $escape->detach();
    $app->postEventAtStart(hiKey($window, 53, true, "\e"), false);
    $session->pump(0);
    NSEvent::removeMonitor($monitor);

    expect([$runs['escaped'], $runs['appkit']])->toBe([1, 1]);
});

it('tracks a window\'s focus: a move from code posts nothing, the user\'s move posts what it left and what it took', function (): void {
    hiInput();
    [$window, $top, $bottom] = hiWindow();
    $session = hiSession();
    $top->input();
    $bottom->input();
    hiDrain($session);
    hiSessionMail($session);

    $top->focus();
    hiDrain($session);
    $fromCode = [$window->focused(), $top->hasFocus(), hiSessionMail($session)];

    // the user's move: AppKit makes the clicked view first responder as it dispatches the click
    $window->native()->makeFirstResponder($bottom->native());
    NSApplication::sharedApplication()->postEventAtStart(hiWake(), false);
    $session->pump(0);
    $mail = hiSessionMail($session);

    expect($window->focusTracker())->toBeInstanceOf(AppkitFocus::class)
        ->and($fromCode)->toBe([$top, true, []])
        ->and(array_map(fn (object $m): array => [$m::class, $m->path], $mail))->toBe([
            [FocusLost::class, $top->path()],
            [FocusGained::class, $bottom->path()],
        ])
        ->and($bottom->hasFocus())->toBeTrue()
        ->and($top->hasFocus())->toBeFalse();
});

it('stops tracking as its window closes', function (): void {
    hiInput();
    [$window, $top] = hiWindow();
    $session = hiSession();
    $tracker = $window->focusTracker();
    $top->input();

    Toolkits::window()->closeAll();
    NSApplication::sharedApplication()->postEventAtStart(hiWake(), false);
    $session->pump(0);

    expect($tracker->holder())->toBeNull()
        ->and(array_filter(hiSessionMail($session), fn (object $m): bool => $m instanceof FocusGained || $m instanceof FocusLost))->toBe([]);
});
