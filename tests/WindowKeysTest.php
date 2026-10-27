<?php

declare(strict_types=1);

use Jovian\Toolkits\Appkit\Windows\AppkitWindow;
use Surface\Contracts\HumanInput\Key;
use Surface\Contracts\HumanInput\Modifiers;
use Surface\Contracts\Windows\Input\EditCommand;
use Surface\Contracts\Windows\Mail\MenuActivated;
use Surface\Contracts\Windows\Mail\View\ButtonClicked;
use Surface\Contracts\Windows\Mail\View\EditCommanded;
use Surface\Contracts\Windows\Mail\View\KeyPressed;
use Surface\Contracts\Windows\Mail\WindowKeyPressed;
use Surface\Contracts\Windows\Primitives\ButtonRole;

const F5_KEY = 96;
const F6_KEY = 97;
const A_KEY = 0;
const J_KEY = 38;
const TAB_KEY = 48;
const RIGHT_BRACKET_KEY = 30;

beforeEach(function (): void {
    driver()->closeAll();
    pumpFor(0.05);
    takeMail(session());
});

/** A shown window with two text inputs, a focusable canvas and one that takes Tab; the menu bar is the test bar. */
function keysWindow(): AppkitWindow
{
    $main = driver()->open('main', 480, 400, driver()->profile('main'));
    $column = $main->column('m');
    $column->textInput('first');
    $column->textInput('second');
    $column->canvas('map')->minSize(200, 60)->setFocusable();
    $column->canvas('editor')->minSize(200, 60)->setFocusable(true, takesTab: true);
    $main->present();
    pumpUntil(fn (): bool => $main->native()->isKeyWindow(), 3.0);
    pumpFor(0.1);
    takeMail(session());

    return $main;
}

/** The WindowKeyPressed mail the session holds, in order. */
function windowKeys(): array
{
    return array_values(array_filter(takeMail(session()), fn (object $mail): bool => $mail instanceof WindowKeyPressed));
}

it('posts a key no focused view took', function (): void {
    $main = keysWindow();
    // No first responder: a focused canvas takes every key itself (CanvasKeysTest).
    $main->native()->makeFirstResponder(null);
    press(F5_KEY, NSEventModifierFlags::FUNCTION->value);
    press(A_KEY);

    expect(windowKeys())->toEqual([
        new WindowKeyPressed('main', Key::F5, '', new Modifiers(), false),
        new WindowKeyPressed('main', Key::A, 'a', new Modifiers(), false),
    ]);
    $main->close();
});

it('posts nothing for a key a focused text input takes', function (): void {
    $main = keysWindow();
    $first = $main->view('m.first');
    $first->focus();
    press(A_KEY);
    // A function key too: the field editor takes every key it is sent (F5 is its completion).
    press(F6_KEY, NSEventModifierFlags::FUNCTION->value);

    expect(windowKeys())->toBe([])
        ->and($first->value())->toContain('a');
    $main->close();
});

it('moves focus on Tab and Shift-Tab and posts no key for them', function (): void {
    $main = keysWindow();
    $main->view('m.first')->focus();
    press(TAB_KEY);
    expect($main->view('m.second')->hasFocus())->toBeTrue();

    press(TAB_KEY);
    expect($main->view('m.map')->hasFocus())->toBeTrue();

    press(TAB_KEY, NSEventModifierFlags::SHIFT->value);
    expect($main->view('m.second')->hasFocus())->toBeTrue()
        ->and(windowKeys())->toBe([]);
    $main->close();
});

it('keeps Tab on a canvas that takes it, as the canvas\'s own key and no window key', function (): void {
    $main = keysWindow();
    $editor = $main->view('m.editor');
    $editor->focus();
    pumpFor(0.05);
    takeMail(session());
    press(TAB_KEY);

    $mail = takeMail(session());
    expect($editor->hasFocus())->toBeTrue()
        ->and(array_filter($mail, fn (object $mail): bool => $mail instanceof WindowKeyPressed))->toBe([])
        ->and(array_values(array_filter($mail, fn (object $mail): bool => $mail instanceof KeyPressed)))->toEqual([
            new KeyPressed('main', 'm.editor', $editor->uuid(), Key::TAB, '', new Modifiers(), false),
        ])
        ->and(array_values(array_filter($mail, fn (object $mail): bool => $mail instanceof EditCommanded)))->toEqual([
            new EditCommanded('main', 'm.editor', $editor->uuid(), EditCommand::InsertTab),
        ]);
    $main->close();
});

it('gives a menu accelerator its item, not WindowKeyPressed', function (): void {
    $main = keysWindow();
    $main->view('m.map')->focus();
    press(RIGHT_BRACKET_KEY, NSEventModifierFlags::COMMAND->value);

    $mail = takeMail(session());
    expect($mail)->toContainEqual(new MenuActivated('main', 'view.tab'))
        ->and(array_filter($mail, fn (object $mail): bool => $mail instanceof WindowKeyPressed))->toBe([]);
    $main->close();
});

it('posts an unhandled key equivalent with its modifier', function (): void {
    $main = keysWindow();
    $main->native()->makeFirstResponder(null);
    press(J_KEY, NSEventModifierFlags::COMMAND->value);

    expect(windowKeys())->toEqual([new WindowKeyPressed('main', Key::J, 'j', new Modifiers(meta: true), false)]);
    $main->close();
});

it('gives Return to a child\'s default button, not WindowKeyPressed', function (): void {
    $main = driver()->open('main', 480, 320);
    $main->column('m')->label('title', 'Main');
    $child = driver()->open('child', 300, 160, null, $main);
    $column = $child->column('c');
    $column->canvas('pad')->minSize(100, 40)->setFocusable();
    $column->button('ok', 'OK')->setRole(ButtonRole::DEFAULT);
    $child->present();
    pumpUntil(fn (): bool => $child->native()->isKeyWindow(), 3.0);
    $child->view('c.pad')->focus();
    takeMail(session());
    press(36);

    $mail = takeMail(session());
    expect(array_values(array_filter($mail, fn (object $mail): bool => $mail instanceof ButtonClicked)))->toHaveCount(1)
        ->and(array_filter($mail, fn (object $mail): bool => $mail instanceof WindowKeyPressed))->toBe([]);
    $main->close();
});
