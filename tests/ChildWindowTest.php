<?php

declare(strict_types=1);

use Surface\Contracts\Windows\Mail\WindowCloseRequested;
use Surface\Contracts\Windows\Mail\WindowClosed;
use Surface\Contracts\Windows\WindowException;

beforeEach(function (): void {
    driver()->closeAll();
    driver()->setDefaultMenuBar(driver()->profile('main'));
    pumpFor(0.05);
    takeMail(session());
});

/** The names of the windows whose WindowClosed or WindowCloseRequested the session holds, in order. */
function closeMail(): array
{
    return array_values(array_map(
        fn (object $mail): string => ($mail instanceof WindowCloseRequested ? 'requested:' : 'closed:').$mail->window,
        array_filter(takeMail(session()), fn (object $mail): bool => $mail instanceof WindowClosed || $mail instanceof WindowCloseRequested),
    ));
}

it('opens a child as a sheet on its parent', function (): void {
    $main = driver()->open('main', 480, 320);
    $main->present();
    $child = driver()->open('child', 300, 120, null, $main);
    $child->present();

    expect(pumpUntil(fn (): bool => $main->native()->attachedSheet() === $child->native(), 2.0))->toBeTrue()
        ->and($child->native()->sheetParent())->toBe($main->native())
        ->and($child->parent())->toBe($main)
        ->and($main->parent())->toBeNull();
});

it('presents the parent first when the parent is not shown', function (): void {
    $main = driver()->open('main', 480, 320);
    $child = driver()->open('child', 300, 120, null, $main);
    $child->present();

    expect(pumpUntil(fn (): bool => $main->native()->attachedSheet() === $child->native(), 2.0))->toBeTrue()
        ->and($main->native()->isVisible())->toBeTrue();
});

it('ends the sheet when the child closes, and gives the parent its input again', function (): void {
    $main = driver()->open('main', 480, 320);
    $child = driver()->open('child', 300, 120, null, $main);
    $child->present();
    pumpUntil(fn (): bool => $main->native()->attachedSheet() === $child->native(), 2.0);
    $child->close();

    expect(pumpUntil(fn (): bool => is_null($main->native()->attachedSheet()), 2.0))->toBeTrue()
        ->and(closeMail())->toBe(['closed:child'])
        ->and(driver()->has('child'))->toBeFalse();
});

it('refuses the user close of a parent that has an open child, presented or not', function (bool $presented): void {
    $main = driver()->open('main', 480, 320);
    $main->present();
    $child = driver()->open('child', 300, 120, null, $main);
    if ($presented) {
        $child->present();
        pumpUntil(fn (): bool => $main->native()->attachedSheet() === $child->native(), 2.0);
    }
    pumpFor(0.1);
    closeMail();

    $main->native()->performClose(null);
    pumpFor(0.2);

    expect(closeMail())->toBe([])
        ->and($main->isOpen())->toBeTrue()
        ->and($child->isOpen())->toBeTrue();
})->with(['not presented' => false, 'presented' => true]);

it('refuses a menu bar for a child', function (): void {
    $main = driver()->open('main', 480, 320);
    $child = driver()->open('child', 300, 120, null, $main);

    expect(fn () => $child->setMenuBar('main'))->toThrow(WindowException::class, "Window 'child' is a child, so it cannot have a menu bar.");
});

it('brings the app forward when the first present of a child begins the sheet', function (): void {
    $main = driver()->open('main', 480, 320);
    $main->present();
    pumpFor(0.2);
    session()->application()->deactivate();
    pumpUntil(fn (): bool => ! session()->application()->isActive(), 2.0);
    $child = driver()->open('child', 300, 120, null, $main);
    $child->present();

    expect(pumpUntil(fn (): bool => session()->application()->isActive(), 2.0))->toBeTrue();
});

it('closes the child first when the parent closes, with no close request from a confirming child', function (): void {
    $main = driver()->open('main', 480, 320);
    $child = driver()->open('child', 300, 120, null, $main)->confirmClose();
    $child->present();
    pumpUntil(fn (): bool => $main->native()->attachedSheet() === $child->native(), 2.0);
    $main->close();
    pumpFor(0.1);

    expect(closeMail())->toBe(['closed:child', 'closed:main'])
        ->and($child->isOpen())->toBeFalse();
});

it('refuses a child as parent, a parent with an open child, and a menu', function (): void {
    $main = driver()->open('main', 480, 320);
    $child = driver()->open('child', 300, 120, null, $main);

    expect(fn () => driver()->open('grandchild', 100, 100, null, $child))->toThrow(WindowException::class, "'child' is a child")
        ->and(fn () => driver()->open('second', 100, 100, null, $main))->toThrow(WindowException::class, "already has the open child 'child'")
        ->and(fn () => driver()->open('menu', 100, 100, driver()->profile('tools'), driver()->open('other', 100, 100)))->toThrow(WindowException::class, 'cannot have a menu bar');
});

it('posts nothing for open with a parent, present and confirmClose', function (): void {
    $main = driver()->open('main', 480, 320);
    $child = driver()->open('child', 300, 120, null, $main)->confirmClose();
    $child->present();
    pumpUntil(fn (): bool => $main->native()->attachedSheet() === $child->native(), 2.0);

    expect(closeMail())->toBe([]);
});

it('blocks every item of the default bar but the edit roles while a child is key', function (): void {
    driver()->setDefaultMenuBar(driver()->profile('editing'));
    $main = driver()->open('main', 480, 320, driver()->profile('tools'));
    $main->present();
    $child = driver()->open('child', 300, 120, null, $main);
    $input = $child->column('m')->textInput('input', 'text');
    $child->present();
    pumpUntil(fn (): bool => $child->isKey(), 2.0);
    $child->native()->makeFirstResponder($input->native());
    $bar = NSApplication::sharedApplication()->mainMenu();
    $edit = $bar->itemAtIndex(1)->submenu();
    $edit->update();
    // The 'editing' profile: Select All 6, Find 8; App holds Quit at 0. AppKit validates the edit
    // roles against the focused text field: Select All is enabled there.
    $items = [];
    foreach (['Select All' => 6, 'Find' => 8] as $title => $index) {
        $items[$title] = $edit->itemAtIndex($index)->isEnabled();
    }

    expect($items)->toBe(['Select All' => true, 'Find' => false])
        ->and($bar->itemAtIndex(0)->submenu()->itemAtIndex(0)->isEnabled())->toBeFalse();

    $child->close();
    $main->present();
    pumpUntil(fn (): bool => $main->isKey(), 2.0);
    expect(NSApplication::sharedApplication()->mainMenu())->toBe($main->menuBar()->menu());

    $main->close();
    pumpFor(0.1);
    $edit->update();
    expect(NSApplication::sharedApplication()->mainMenu()->itemAtIndex(1)->submenu()->itemAtIndex(8)->isEnabled())->toBeTrue();
});

it('shows an empty menu while a child is key and no default profile is set', function (): void {
    driver()->setDefaultMenuBar(null);
    $main = driver()->open('main', 480, 320, driver()->profile('tools'));
    $child = driver()->open('child', 300, 120, null, $main);
    $child->present();
    pumpUntil(fn (): bool => $child->isKey(), 2.0);

    expect(NSApplication::sharedApplication()->mainMenu()->numberOfItems())->toBe(0);
});
