<?php

declare(strict_types=1);

beforeEach(function (): void {
    driver()->closeAll();
    pumpFor(0.05);
    takeMail(session());
});

/** The Edit item's index in its menu, to perform it as AppKit does. */
function performEdit(Jovian\Toolkits\Appkit\Windows\AppkitWindow $window, string $id): void
{
    $item = $window->menuBar()->item($id);
    $item->menu()->performActionForItemAtIndex($item->menu()->indexOfItem($item));
    pumpFor(0.05);
}

it('gives edit items AppKit\'s own actions, no target and the standard key equivalents', function (): void {
    $bar = driver()->open('main', 320, 200, driver()->profile('editing'))->menuBar();
    $read = fn (string $id): array => [$bar->item($id)->action(), $bar->item($id)->target(), $bar->item($id)->keyEquivalent()];

    expect([$read('edit.undo'), $read('edit.redo'), $read('edit.cut'), $read('edit.copy'), $read('edit.paste'), $read('edit.select-all')])
        ->toBe([['undo:', null, 'z'], ['redo:', null, 'Z'], ['cut:', null, 'x'], ['copy:', null, 'c'], ['paste:', null, 'v'], ['selectAll:', null, 'a']])
        ->and($bar->item('edit.copy')->menu()->autoenablesItems())->toBeTrue()
        ->and($bar->item('app.quit')->menu()->autoenablesItems())->toBeFalse();
});

it('keeps a custom item in the Edit folder enabled, and greys the edit items out with nothing to take them', function (): void {
    $window = driver()->open('main', 320, 200, driver()->profile('editing'));
    $edit = $window->menuBar()->item('edit.copy')->menu();

    $edit->update();

    expect($window->menuBar()->item('edit.find')->isEnabled())->toBeTrue()
        ->and($window->menuBar()->item('edit.copy')->isEnabled())->toBeFalse()
        ->and($window->menuBar()->item('edit.select-all')->isEnabled())->toBeFalse();
});

it('selects all in the focused text input and undoes typing over it', function (): void {
    $window = driver()->open('main', 360, 160, driver()->profile('editing'));
    $input = $window->column('c')->textInput('name', 'hello world');
    $window->present();
    expect(pumpUntil(fn () => $window->isKey(), 5.0))->toBeTrue();
    $window->native()->makeFirstResponder($input->native());
    pumpFor(0.05);

    performEdit($window, 'edit.select-all');
    session()->application()->postEventAtStart(NSEvent::keyEventWithTypeLocationModifierFlagsTimestampWindowNumberContextCharactersCharactersIgnoringModifiersIsARepeatKeyCode(
        NSEventType::KEY_DOWN, new NSPoint(), 0, 0.0, $window->native()->windowNumber(), null, 'x', 'x', false, 0x07,
    ), false);
    pumpFor(0.1);
    $typed = $input->native()->stringValue();
    performEdit($window, 'edit.undo');
    $window->native()->makeFirstResponder(null);   // ends editing: the field editor's caret timer would wake later pumps

    expect($typed)->toBe('x')
        ->and($input->native()->stringValue())->toBe('hello world');
});
