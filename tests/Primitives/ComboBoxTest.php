<?php

declare(strict_types=1);

use Surface\Contracts\Windows\Mail\View\SelectionChanged;
use Surface\Contracts\Windows\Mail\View\TextChanged;
use Surface\Contracts\Windows\Mail\View\TextSubmitted;

beforeEach(function (): void {
    driver()->closeAll();
    pumpFor(0.05);
    takeMail(session());
});

afterEach(function (): void {
    driver()->closeAll();
    pumpFor(0.05);
    takeMail(session());
});

/** A shown window with a combo and a text input, the combo focused, the mail drained. */
function comboWindow(): array
{
    $window = driver()->open('main', 360, 220);
    $main = $window->column('m');
    $combo = $main->comboBox('path', '/tmp', ['/Users', '/Volumes']);
    $other = $main->textInput('other', 'x');
    $window->present();
    pumpUntil(fn (): bool => $window->native()->isKeyWindow(), 3.0);
    $combo->focus();
    pumpFor(0.1);
    takeMail(session());

    return [$window, $combo, $other];
}

it('holds its options and value in the native, and posts nothing for code', function (): void {
    [$window, $combo] = comboWindow();

    $combo->setValue('/Volumes/x');
    $combo->setOptions(['/a', '/b', '/c']);

    expect($combo->native()->numberOfItems())->toBe(3)
        ->and($combo->native()->stringValue())->toBe('/Volumes/x')
        ->and(takeMail(session()))->toBe([]);
    $window->close();
});

it('posts TextChanged for typing', function (): void {
    [$window, $combo] = comboWindow();

    press(0);

    expect(takeMail(session()))->toEqual([new TextChanged('main', 'm.path', $combo->uuid(), $combo->value())])
        ->and($combo->value())->toEndWith('a');
    $window->close();
});

it('shows completions under the focused field, and drops them on a field without focus', function (): void {
    [$window, $combo, $other] = comboWindow();

    $combo->setCompletions(['/Users/angel', '/Users/Shared']);
    pumpFor(0.1);
    expect($combo->completionList()->isShown())->toBeTrue()
        ->and($combo->completionList()->rows())->toBe(['/Users/angel', '/Users/Shared'])
        ->and($combo->completions())->toBe(['/Users/angel', '/Users/Shared']);

    $combo->setCompletions([]);
    expect($combo->completionList()->isShown())->toBeFalse();

    $other->focus();
    pumpFor(0.1);
    $combo->setCompletions(['/Users/angel']);
    expect($combo->completionList()->isShown())->toBeFalse()
        ->and($combo->completions())->toBe([]);
    $window->close();
});

it('chooses the highlighted completion with Down and Return', function (): void {
    [$window, $combo] = comboWindow();
    $combo->setCompletions(['/Users/angel', '/Users/Shared']);
    pumpFor(0.1);

    press(125);
    press(36);

    expect(takeMail(session()))->toEqual([new SelectionChanged('main', 'm.path', $combo->uuid(), -1, '/Users/angel')])
        ->and($combo->value())->toBe('/Users/angel')
        ->and($combo->native()->stringValue())->toBe('/Users/angel')
        ->and($combo->completionList()->isShown())->toBeFalse()
        ->and($combo->completions())->toBe([]);
    $window->close();
});

it('posts TextSubmitted for Return with no completion highlighted', function (): void {
    [$window, $combo] = comboWindow();
    $combo->setCompletions(['/Users/angel']);
    pumpFor(0.1);

    press(36);

    expect(takeMail(session()))->toEqual([new TextSubmitted('main', 'm.path', $combo->uuid(), '/tmp')]);
    $window->close();
});

it('hides on focus loss, with no mail', function (): void {
    [$window, $combo, $other] = comboWindow();
    $combo->setCompletions(['/Users/angel']);
    pumpFor(0.1);

    $other->focus();
    pumpFor(0.1);

    expect($combo->completionList()->isShown())->toBeFalse()
        ->and($combo->completions())->toBe([])
        ->and(array_filter(takeMail(session()), fn (object $mail): bool => $mail instanceof SelectionChanged || $mail instanceof TextSubmitted))->toBe([]);
    $window->close();
});

it('replaces shown rows with nothing highlighted and no mail', function (): void {
    [$window, $combo] = comboWindow();
    $combo->setCompletions(['/Users/angel', '/Users/Shared']);
    pumpFor(0.1);
    press(125);

    $combo->setCompletions(['/Volumes/a']);
    pumpFor(0.1);

    expect($combo->completionList()->rows())->toBe(['/Volumes/a'])
        ->and($combo->completionList()->highlighted())->toBe(-1)
        ->and(takeMail(session()))->toBe([]);
    $window->close();
});

it('fits the list to its rows, at most eight, after a longer list', function (): void {
    [$window, $combo] = comboWindow();
    $height = function (int $rows) use ($combo): float {
        $combo->setCompletions(array_map(fn (int $i): string => "/Users/u{$i}", range(1, $rows)));
        pumpFor(0.1);

        return $combo->completionList()->panel()->frame()->height;
    };

    $twenty = $height(20);
    $two = $height(2);
    $three = $height(3);
    $eight = $height(8);

    // Each row adds the same height; past eight the list scrolls.
    expect($three - $two)->toBeGreaterThan(10.0)
        ->and($eight - $three)->toEqualWithDelta(5 * ($three - $two), 0.5)
        ->and($twenty)->toEqualWithDelta($eight, 0.5);
    $window->close();
});

it('hides on Esc', function (): void {
    [$window, $combo] = comboWindow();
    $combo->setCompletions(['/Users/angel']);
    pumpFor(0.1);

    press(53);

    expect($combo->completionList()->isShown())->toBeFalse()
        ->and($combo->completions())->toBe([])
        ->and(takeMail(session()))->toBe([]);
    $window->close();
});

it('posts the option index for a choice from the dropdown', function (): void {
    [$window, $combo] = comboWindow();

    // A user's pick: the native selection changes outside any code path of the driver.
    $combo->native()->selectItemAtIndex(1);
    pumpFor(0.1);

    expect(takeMail(session()))->toEqual([new SelectionChanged('main', 'm.path', $combo->uuid(), 1, '/Volumes')])
        ->and($combo->value())->toBe('/Volumes');
    $window->close();
});
