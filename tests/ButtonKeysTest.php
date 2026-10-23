<?php

declare(strict_types=1);

use Jovian\Toolkits\Appkit\Windows\AppkitWindow;
use Surface\Contracts\Windows\Mail\View\ButtonClicked;
use Surface\Contracts\Windows\Mail\View\NodeActivated;
use Surface\Contracts\Windows\Mail\View\TextSubmitted;
use Surface\Contracts\Windows\Mail\WindowCloseRequested;
use Surface\Contracts\Windows\Mail\WindowClosed;
use Surface\Contracts\Windows\Primitives\ButtonRole;
use Surface\Contracts\Windows\Primitives\TableColumn;
use Surface\Contracts\Windows\Primitives\TreeNode;

const RETURN_KEY = 36;
const ENTER_KEY = 76;
const ESC_KEY = 53;

beforeEach(function (): void {
    driver()->closeAll();
    pumpFor(0.05);
    takeMail(session());
});

/** A window with a text input, a tree, a text area, an OK button (DEFAULT) and a Cancel button (CANCEL); presented. */
function rolesWindow(): AppkitWindow
{
    $window = driver()->open('main', 480, 400);
    $column = $window->column('m');
    $column->textInput('input', '12');
    $column->tree('tree', [new TableColumn('name', 'Name')], [new TreeNode('a', ['name' => 'a'])]);
    $column->textArea('area', 'one');
    $column->button('ok', 'OK')->setRole(ButtonRole::DEFAULT);
    $column->button('cancel', 'Cancel')->setRole(ButtonRole::CANCEL);
    $window->present();
    pumpFor(0.2);
    takeMail(session());

    return $window;
}

/** A key-down at the window, posted to the front of the queue and pumped through the session and its taps. */
function roleKey(AppkitWindow $window, int $code, int $flags = 0): void
{
    $characters = match ($code) {
        RETURN_KEY => mb_chr(NSCarriageReturnCharacter),
        ENTER_KEY => mb_chr(NSEnterCharacter),
        default => "\e",
    };
    $event = NSEvent::keyEventWithTypeLocationModifierFlagsTimestampWindowNumberContextCharactersCharactersIgnoringModifiersIsARepeatKeyCode(
        NSEventType::KEY_DOWN, new NSPoint(0.0, 0.0), $flags, 0.0, $window->native()->windowNumber(), null, $characters, $characters, false, $code,
    );
    NSApplication::sharedApplication()->postEventAtStart($event, true);
    pumpFor(0.1);
}

/** Focus a primitive from code (the outline of a tree, the text view of a text area): no focus mail. */
function focusOn(AppkitWindow $window, string $path): void
{
    $window->view($path)->focus();
    pumpFor(0.05);
    // What the move posted (the other field's editing ended) is not the key's.
    takeMail(session());
}

/** The class and button of each view mail, in order. */
function roleMail(): array
{
    return array_map(fn (object $mail): string => mailName($mail).($mail instanceof ButtonClicked ? ':'.$mail->path : ''), viewMail(session()));
}

/** The short class name of a mail. */
function mailName(object $mail): string
{
    return substr(strrchr(get_class($mail), '\\'), 1);
}

it('clicks the holder for Return, keypad Enter and Esc, from a text input and from a tree', function (string $path): void {
    $window = rolesWindow();
    focusOn($window, $path);
    roleKey($window, RETURN_KEY);
    roleKey($window, ENTER_KEY);
    roleKey($window, ESC_KEY);

    expect(roleMail())->toBe(['ButtonClicked:m.ok', 'ButtonClicked:m.ok', 'ButtonClicked:m.cancel']);
})->with(['m.input', 'm.tree']);

it('gives Return to a focused text area, and Esc to the cancel button', function (): void {
    $window = rolesWindow();
    focusOn($window, 'm.area');
    roleKey($window, RETURN_KEY);
    roleKey($window, ESC_KEY);

    // The new line posts TextChanged.
    expect(roleMail())->toBe(['TextChanged', 'ButtonClicked:m.cancel'])
        ->and(substr_count($window->view('m.area')->value(), "\n"))->toBe(1);
});

it('lets Return with Shift go to the focused widget', function (): void {
    $window = rolesWindow();
    focusOn($window, 'm.input');
    roleKey($window, RETURN_KEY, NSEventModifierFlags::SHIFT->value);

    expect(roleMail())->not->toContain('ButtonClicked:m.ok');
});

it('lets Return go to the focused widget when the holder cannot take the key', function (string $case): void {
    $window = rolesWindow();
    $ok = $window->view('m.ok');
    if ($case === 'disabled') {
        $ok->setEnabled(false);
    } elseif ($case === 'hidden group') {
        $ok->remove();
        $window->content()->row('r')->button('ok2', 'OK')->setRole(ButtonRole::DEFAULT);
        $window->view('m.r')->hide();
    } else {
        $ok->remove();
        $tabs = $window->content()->tabs('tabs');
        $tabs->addPage('one', 'One');
        $tabs->addPage('two', 'Two')->button('ok3', 'OK')->setRole(ButtonRole::DEFAULT);
        $tabs->select('one');
    }
    pumpFor(0.1);
    focusOn($window, 'm.input');
    roleKey($window, RETURN_KEY);

    expect(roleMail())->toBe(['TextSubmitted']);
})->with(['disabled', 'hidden group', 'tab not selected']);

it('closes a child that has no cancel button on Esc, or asks when it confirms close', function (): void {
    $main = driver()->open('main', 480, 320);
    $note = driver()->open('note', 200, 100, null, $main);
    $note->column('m')->label('l', 'Note');
    $note->confirmClose()->present();
    pumpUntil(fn (): bool => $main->native()->attachedSheet() === $note->native(), 2.0);
    takeMail(session());
    roleKey($note, ESC_KEY);

    $closing = fn (): array => array_values(array_filter(takeMail(session()), fn (object $mail): bool => $mail instanceof WindowCloseRequested || $mail instanceof WindowClosed));

    expect($closing())->toEqual([new WindowCloseRequested('note')])
        ->and($note->isOpen())->toBeTrue();

    $note->confirmClose(false);
    roleKey($note, ESC_KEY);
    pumpFor(0.1);

    expect($closing())->toEqual([new WindowClosed('note')])
        ->and($note->isOpen())->toBeFalse();
});

it('draws the default holder as the default button, and moves the look with the role', function (): void {
    $window = rolesWindow();
    $ok = $window->view('m.ok');

    expect($ok->native()->keyEquivalent())->toBe("\r");

    $window->content()->button('other', 'Other')->setRole(ButtonRole::DEFAULT);
    expect($ok->native()->keyEquivalent())->toBe('')
        ->and($window->view('m.other')->native()->keyEquivalent())->toBe("\r")
        ->and(viewMail(session()))->toBe([]);
});
