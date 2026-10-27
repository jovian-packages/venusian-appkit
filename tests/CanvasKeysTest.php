<?php

declare(strict_types=1);

use Jovian\Toolkits\Appkit\Windows\AppkitWindow;
use Surface\Contracts\HumanInput\Key;
use Surface\Contracts\HumanInput\Modifiers;
use Surface\Contracts\Windows\Input\EditCommand;
use Surface\Contracts\Windows\Mail\MenuActivated;
use Surface\Contracts\Windows\Mail\View\ButtonClicked;
use Surface\Contracts\Windows\Mail\View\EditCommanded;
use Surface\Contracts\Windows\Mail\View\FocusGained;
use Surface\Contracts\Windows\Mail\View\KeyPressed;
use Surface\Contracts\Windows\Mail\View\KeyReleased;
use Surface\Contracts\Windows\Mail\View\TextCommitted;
use Surface\Contracts\Windows\Mail\WindowKeyPressed;
use Surface\Contracts\Windows\Primitives\ButtonRole;

// Key codes (Carbon kVK_): A 0, E 14, K 40, Return 36, Tab 48, Esc 53, Backspace 51, Shift 56, Left 123, Up 126.

beforeEach(function (): void {
    driver()->closeAll();
    pumpFor(0.05);
    takeMail(session());
});

/** A shown window with two text inputs, a focusable canvas `map` and a canvas `editor` that takes Tab; the menu bar is the test bar. */
function canvasKeysWindow(): AppkitWindow
{
    $main = driver()->open('main', 480, 400, driver()->profile('main'));
    $column = $main->column('m');
    $column->textInput('first');
    $column->textInput('second');
    $column->canvas('map')->minSize(200, 60)->setFocusable();
    $column->canvas('editor')->minSize(200, 60)->setFocusable(true, takesTab: true);
    $main->present();
    pumpUntil(fn (): bool => $main->native()->isKeyWindow(), 3.0);
    // The focus view's input context activates on the run-loop pass after it becomes first responder.
    $main->view('m.editor')->focus();
    pumpFor(0.1);
    takeMail(session());

    return $main;
}

/** The canvas key mail the session holds, in order, plus any WindowKeyPressed. */
function canvasKeys(): array
{
    return array_values(array_filter(takeMail(session()), fn (object $mail): bool => $mail instanceof KeyPressed || $mail instanceof KeyReleased
        || $mail instanceof TextCommitted || $mail instanceof EditCommanded || $mail instanceof WindowKeyPressed));
}

/** A modifier key alone: down with its flag set, up with the flags cleared. */
function pressModifier(int $code, int $flag): void
{
    $app = NSApplication::sharedApplication();
    $up = CGEvent::createKeyboardEvent(null, $code, false);
    $up->setFlags(0);
    $app->postEventAtStart(keyDown($code, $flag), false);
    $app->postEventAtStart(NSEvent::eventWithCGEvent($up), false);
    pumpFor(0.05);
}

it('posts the key, the committed text and the release for a letter on a focused canvas', function (): void {
    $main = canvasKeysWindow();
    press(0);

    expect(canvasKeys())->toEqual([
        new KeyPressed('main', 'm.editor', $main->view('m.editor')->uuid(), Key::A, 'a', new Modifiers(), false),
        new TextCommitted('main', 'm.editor', $main->view('m.editor')->uuid(), 'a'),
        new KeyReleased('main', 'm.editor', $main->view('m.editor')->uuid(), Key::A, new Modifiers()),
    ]);
    $main->close();
});

it('posts the platform\'s edit command for a bound key', function (int $code, int $flags, EditCommand $command): void {
    $main = canvasKeysWindow();
    press($code, $flags);

    $commands = array_values(array_filter(canvasKeys(), fn (object $mail): bool => $mail instanceof EditCommanded));
    expect($commands)->toHaveCount(1)
        ->and($commands[0]->command)->toBe($command)
        ->and($commands[0]->path)->toBe('m.editor');
    $main->close();
})->with([
    'Left' => [123, 0, EditCommand::MoveLeft],
    'Shift+Left' => [123, NSEventModifierFlags::SHIFT->value, EditCommand::SelectLeft],
    'Cmd+Left' => [123, NSEventModifierFlags::COMMAND->value, EditCommand::MoveToLineStart],
    'Option+Left' => [123, NSEventModifierFlags::OPTION->value, EditCommand::MoveWordLeft],
    'Cmd+Up' => [126, NSEventModifierFlags::COMMAND->value, EditCommand::MoveToDocumentStart],
    'Backspace' => [51, 0, EditCommand::DeleteBackward],
    'Option+Backspace' => [51, NSEventModifierFlags::OPTION->value, EditCommand::DeleteWordBackward],
    'Return' => [36, 0, EditCommand::InsertNewline],
    'Ctrl+K' => [40, NSEventModifierFlags::CONTROL->value, EditCommand::DeleteToLineEnd],
    'Esc with no cancel button' => [53, 0, EditCommand::Cancel],
    'Tab on a canvas that takes it' => [48, 0, EditCommand::InsertTab],
    'Shift+Tab on a canvas that takes it' => [48, NSEventModifierFlags::SHIFT->value, EditCommand::InsertBacktab],
]);

it('posts KeyPressed before the command and no text for a bound key, and no WindowKeyPressed for any', function (): void {
    $main = canvasKeysWindow();
    press(48);
    press(123);

    $mail = canvasKeys();
    expect(array_map(fn (object $mail): string => $mail::class, $mail))->toBe([
        KeyPressed::class, EditCommanded::class, KeyReleased::class,
        KeyPressed::class, EditCommanded::class, KeyReleased::class,
    ])
        ->and($mail[0]->key)->toBe(Key::TAB)
        ->and($mail[3]->key)->toBe(Key::LEFT);
    $main->close();
});

it('posts a lone modifier as a key press and release', function (): void {
    $main = canvasKeysWindow();
    pressModifier(56, NSEventModifierFlags::SHIFT->value);

    expect(canvasKeys())->toEqual([
        new KeyPressed('main', 'm.editor', $main->view('m.editor')->uuid(), Key::LEFT_SHIFT, '', new Modifiers(shift: true), false),
        new KeyReleased('main', 'm.editor', $main->view('m.editor')->uuid(), Key::LEFT_SHIFT, new Modifiers()),
    ]);
    $main->close();
});

it('Tab leaves a canvas that does not take it, posting no canvas mail', function (): void {
    $main = canvasKeysWindow();
    $main->view('m.map')->focus();
    pumpFor(0.05);
    takeMail(session());
    press(48);

    $mail = takeMail(session());
    expect($main->view('m.editor')->hasFocus())->toBeTrue()
        ->and(array_values(array_filter($mail, fn (object $mail): bool => $mail instanceof FocusGained)))->toEqual([
            new FocusGained('main', 'm.editor', $main->view('m.editor')->uuid()),
        ])
        ->and(array_filter($mail, fn (object $mail): bool => $mail instanceof KeyPressed || $mail instanceof EditCommanded || $mail instanceof WindowKeyPressed))->toBe([]);
    $main->close();
});

it('an accelerator goes to its item, not to the focused canvas', function (): void {
    $main = canvasKeysWindow();
    press(30, NSEventModifierFlags::COMMAND->value);

    $mail = takeMail(session());
    expect($mail)->toContainEqual(new MenuActivated('main', 'view.tab'))
        ->and(array_filter($mail, fn (object $mail): bool => $mail instanceof KeyPressed || $mail instanceof EditCommanded))->toBe([]);
    $main->close();
});

it('a role key goes to its button, not to the focused canvas', function (): void {
    $main = driver()->open('main', 480, 320);
    $main->column('m')->label('title', 'Main');
    $child = driver()->open('child', 300, 160, null, $main);
    $column = $child->column('c');
    $column->canvas('pad')->minSize(100, 40)->setFocusable();
    $column->button('cancel', 'Cancel')->setRole(ButtonRole::CANCEL);
    $child->present();
    pumpUntil(fn (): bool => $child->native()->isKeyWindow(), 3.0);
    $child->view('c.pad')->focus();
    pumpFor(0.1);
    takeMail(session());
    press(53);

    $mail = takeMail(session());
    expect(array_values(array_filter($mail, fn (object $mail): bool => $mail instanceof ButtonClicked)))->toHaveCount(1)
        ->and(array_filter($mail, fn (object $mail): bool => $mail instanceof KeyPressed || $mail instanceof EditCommanded))->toBe([]);
    $main->close();
});

it('composes a dead key into one committed text', function (): void {
    $main = canvasKeysWindow();
    press(14, NSEventModifierFlags::OPTION->value);
    press(14);

    $mail = canvasKeys();
    $text = array_values(array_filter($mail, fn (object $mail): bool => $mail instanceof TextCommitted));
    expect($text)->toEqual([new TextCommitted('main', 'm.editor', $main->view('m.editor')->uuid(), 'é')])
        ->and(array_filter($mail, fn (object $mail): bool => $mail instanceof KeyPressed))->toHaveCount(2);
    $main->close();
});

it('posts nothing from a canvas that is not focused', function (): void {
    $main = canvasKeysWindow();
    press(0);

    expect(array_filter(canvasKeys(), fn (object $mail): bool => $mail->path !== 'm.editor'))->toBe([]);
    $main->close();
});

it('releases the modifiers it reported pressed when their flags drop, whatever key the event names', function (): void {
    $main = canvasKeysWindow();
    $uuid = $main->view('m.editor')->uuid();
    $app = NSApplication::sharedApplication();
    $flags = fn (int $code, int $flags): NSEvent => NSEvent::keyEventWithTypeLocationModifierFlagsTimestampWindowNumberContextCharactersCharactersIgnoringModifiersIsARepeatKeyCode(
        NSEventType::FLAGS_CHANGED, new NSPoint(0.0, 0.0), $flags, 0.0, $main->native()->windowNumber(), null, '', '', false, $code,
    );
    // Shift, then Command, go down; their releases go elsewhere; back in the window one change with key code 0 clears both.
    $app->postEventAtStart($flags(56, NSEventModifierFlags::SHIFT->value), false);
    $app->postEventAtStart($flags(55, NSEventModifierFlags::SHIFT->value | NSEventModifierFlags::COMMAND->value), false);
    $app->postEventAtStart($flags(0, 0), false);
    pumpFor(0.1);

    expect(canvasKeys())->toEqual([
        new KeyPressed('main', 'm.editor', $uuid, Key::LEFT_SHIFT, '', new Modifiers(shift: true), false),
        new KeyPressed('main', 'm.editor', $uuid, Key::LEFT_META, '', new Modifiers(shift: true, meta: true), false),
        new KeyReleased('main', 'm.editor', $uuid, Key::LEFT_SHIFT, new Modifiers()),
        new KeyReleased('main', 'm.editor', $uuid, Key::LEFT_META, new Modifiers()),
    ]);
    $main->close();
});

it('posts the release of a key held with Command', function (): void {
    $main = canvasKeysWindow();
    press(123, NSEventModifierFlags::COMMAND->value);

    $releases = array_values(array_filter(canvasKeys(), fn (object $mail): bool => $mail instanceof KeyReleased));
    expect($releases)->toEqual([new KeyReleased('main', 'm.editor', $main->view('m.editor')->uuid(), Key::LEFT, new Modifiers(meta: true))]);
    $main->close();
});

it('takes the Edit menu\'s roles while focused: each posts its command, and Command-A is Select All', function (): void {
    $main = driver()->open('main', 480, 300, driver()->profile('editing'));
    $main->column('m')->canvas('editor')->minSize(200, 60)->setFocusable();
    $main->present();
    pumpUntil(fn (): bool => $main->native()->isKeyWindow(), 3.0);
    $main->view('m.editor')->focus();
    pumpFor(0.1);
    takeMail(session());
    foreach (['edit.cut', 'edit.copy', 'edit.paste', 'edit.undo', 'edit.redo'] as $id) {
        $item = $main->menuBar()->item($id);
        $item->menu()->update();
        expect($item->isEnabled())->toBeTrue();
        $item->menu()->performActionForItemAtIndex($item->menu()->indexOfItem($item));
    }
    pumpFor(0.05);
    press(0, NSEventModifierFlags::COMMAND->value);

    $mail = canvasKeys();
    expect(array_map(fn (object $mail): EditCommand => $mail->command, array_values(array_filter($mail, fn (object $mail): bool => $mail instanceof EditCommanded))))
        ->toBe([EditCommand::Cut, EditCommand::Copy, EditCommand::Paste, EditCommand::Undo, EditCommand::Redo, EditCommand::SelectAll])
        ->and(array_filter($mail, fn (object $mail): bool => $mail instanceof KeyPressed))->toBe([]);
    $main->close();
});

it('posts a modifier\'s press after its release went to another view, and each modifier\'s release by its own key', function (): void {
    $main = canvasKeysWindow();
    $uuid = $main->view('m.editor')->uuid();
    $app = NSApplication::sharedApplication();
    $flags = fn (int $code, int $flags): NSEvent => NSEvent::keyEventWithTypeLocationModifierFlagsTimestampWindowNumberContextCharactersCharactersIgnoringModifiersIsARepeatKeyCode(
        NSEventType::FLAGS_CHANGED, new NSPoint(0.0, 0.0), $flags, 0.0, $main->native()->windowNumber(), null, '', '', false, $code,
    );
    $shift = NSEventModifierFlags::SHIFT->value;
    $option = NSEventModifierFlags::OPTION->value;
    // Shift down on the canvas, up on the text input.
    $app->postEventAtStart($flags(56, $shift), false);
    pumpFor(0.05);
    $main->native()->makeFirstResponder($main->view('m.first')->native());
    $app->postEventAtStart($flags(56, 0), false);
    pumpFor(0.05);
    $main->view('m.editor')->focus();
    pumpFor(0.05);
    // Shift alone again; then Shift held, Option down and up: Option releases while Shift stays.
    foreach ([[56, $shift], [56, 0], [56, $shift], [58, $shift | $option], [58, $shift], [56, 0]] as [$code, $now]) {
        $app->postEventAtStart($flags($code, $now), false);
    }
    pumpFor(0.1);

    $mail = array_map(fn (object $mail): string => (new ReflectionClass($mail))->getShortName().' '.$mail->key->name, canvasKeys());
    expect($mail)->toBe([
        'KeyPressed LEFT_SHIFT',
        'KeyPressed LEFT_SHIFT', 'KeyReleased LEFT_SHIFT',
        'KeyPressed LEFT_SHIFT', 'KeyPressed LEFT_ALT', 'KeyReleased LEFT_ALT', 'KeyReleased LEFT_SHIFT',
    ]);
    $main->close();
});
