<?php

declare(strict_types=1);

use Jovian\Toolkits\Appkit\Windows\AppkitWindow;
use Surface\Contracts\Windows\Mail\View\FocusGained;
use Surface\Contracts\Windows\Mail\View\FocusLost;
use Surface\Contracts\Windows\Primitives\TableColumn;
use Surface\Contracts\Windows\Primitives\TreeNode;
use Surface\Contracts\Windows\WindowException;

/** The A key: a key-down after a change, so the tracker compares after an event. */
const A_LETTER = 0;

beforeEach(function (): void {
    driver()->closeAll();
    pumpFor(0.05);
    takeMail(session());
});

/** The focus mail the session holds, in order, as short strings. */
function focusMail(): array
{
    return array_values(array_filter(array_map(fn (object $mail): ?string => match (true) {
        $mail instanceof FocusGained => "gained:{$mail->path}",
        $mail instanceof FocusLost => "lost:{$mail->path}",
        default => null,
    }, takeMail(session()))));
}

/** A shown window with two text inputs and a canvas. */
function focusWindow(): AppkitWindow
{
    $main = driver()->open('main', 480, 320);
    $column = $main->column('m');
    $column->textInput('first');
    $column->textInput('second');
    $column->canvas('map')->minSize(200, 80);
    $main->present();
    pumpUntil(fn (): bool => $main->native()->isKeyWindow(), 3.0);
    pumpFor(0.1);
    takeMail(session());

    return $main;
}

/** A left click near the top-left of $view, posted and pumped through the session as the user's. */
function clickOn(AppkitWindow $window, NSView $view): void
{
    $at = canvasWindowPoint($window, $view, 5.0, 5.0);
    $app = NSApplication::sharedApplication();
    // Queued at the end, press first: a text field tracks the press until the release comes.
    $app->postEventAtStart(mouseAt($window, $at, NSEventType::LEFT_MOUSE_DOWN), false);
    $app->postEventAtStart(mouseAt($window, $at, NSEventType::LEFT_MOUSE_UP), false);
    pumpFor(0.2);
}

it('focuses a shown input through AppKit and posts nothing', function (): void {
    $main = focusWindow();
    $first = $main->view('m.first');
    $first->focus();
    pumpFor(0.1);

    $responder = $main->native()->firstResponder();
    expect($responder)->toBeInstanceOf(NSTextView::class)
        ->and($responder->delegate())->toBe($first->native())
        ->and($first->hasFocus())->toBeTrue()
        ->and($main->view('m.second')->hasFocus())->toBeFalse()
        ->and($main->focused())->toBe($first)
        ->and(focusMail())->toBe([]);
    $main->close();
});

it('focuses the inner view of a text area, a table and a tree', function (): void {
    $main = driver()->open('main', 480, 400);
    $column = $main->column('m');
    $area = $column->textArea('area');
    $table = $column->table('table', [new TableColumn('name', 'Name')], [['name' => 'a']]);
    $tree = $column->tree('tree', [new TableColumn('name', 'Name')], [new TreeNode('a', ['name' => 'a'])]);
    $main->present();
    pumpFor(0.2);

    $area->focus();
    expect($main->native()->firstResponder())->toBe($area->textView())
        ->and($area->hasFocus())->toBeTrue();

    $table->focus();
    expect($main->native()->firstResponder())->toBe($table->focusView())
        ->and($table->focusView())->toBeInstanceOf(NSTableView::class)
        ->and($main->focused())->toBe($table);

    $tree->focus();
    expect($main->native()->firstResponder())->toBe($tree->outline())
        ->and($tree->hasFocus())->toBeTrue()
        ->and(focusMail())->toBe([]);
    $main->close();
});

it('refuses focus to a canvas until it is focusable, and lets go when it no longer is', function (): void {
    $main = focusWindow();
    $canvas = $main->view('m.map');

    expect($canvas->native())->toBeInstanceOf(ObjCFocusView::class)
        ->and($canvas->native()->acceptsFirstResponder())->toBeFalse()
        ->and(fn () => $canvas->focus())->toThrow(WindowException::class, "'m.map' cannot take focus.");

    $canvas->setFocusable();
    $canvas->focus();
    expect($canvas->native()->acceptsFirstResponder())->toBeTrue()
        ->and($main->native()->firstResponder())->toBe($canvas->native())
        ->and($canvas->hasFocus())->toBeTrue();

    $canvas->setFocusable(false);
    expect($canvas->hasFocus())->toBeFalse()
        ->and($main->focused())->toBeNull()
        ->and(focusMail())->toBe([]);
    $main->close();
});

it('refuses focus to a label, which takes none', function (): void {
    $main = driver()->open('main', 480, 320);
    $label = $main->column('m')->label('title', 'Title');
    $main->present();
    pumpFor(0.1);

    expect(fn () => $label->focus())->toThrow(WindowException::class, "'m.title' cannot take focus.");
    $main->close();
});

it('posts the user\'s click from one input to the other once, after a focus() that posted nothing', function (): void {
    $main = focusWindow();
    $main->view('m.first')->focus();
    pumpFor(0.1);
    expect(focusMail())->toBe([]);

    clickOn($main, $main->view('m.second')->native());

    expect($main->view('m.second')->hasFocus())->toBeTrue()
        ->and(focusMail())->toBe(['lost:m.first', 'gained:m.second']);
    $main->close();
});

it('focuses a focusable canvas on a click, and not a default one', function (): void {
    $main = focusWindow();
    $main->view('m.first')->focus();
    $canvas = $main->view('m.map');

    clickOn($main, $canvas->native());
    expect($canvas->hasFocus())->toBeFalse()
        ->and(focusMail())->toBe([]);

    $canvas->setFocusable();
    clickOn($main, $canvas->native());
    expect($canvas->hasFocus())->toBeTrue()
        ->and(focusMail())->toBe(['lost:m.first', 'gained:m.map']);
    $main->close();
});

it('focuses a focusable canvas on a click on a view lent over it, which takes no focus', function (): void {
    $main = focusWindow();
    $main->view('m.first')->focus();
    $canvas = $main->view('m.map')->setFocusable();
    // Stands in for a lent NSOpenGLView: pinned over the canvas, and AppKit makes it no first responder.
    $over = NSView::initWithFrame(new NSRect(0.0, 0.0, 200.0, 80.0));
    $canvas->native()->addSubview($over);

    clickOn($main, $over);
    expect($over->acceptsFirstResponder())->toBeFalse()
        ->and($canvas->hasFocus())->toBeTrue()
        ->and(focusMail())->toBe(['lost:m.first', 'gained:m.map']);
    $main->close();
});

it('focuses the initial primitive on present, posting nothing', function (): void {
    $main = driver()->open('main', 480, 320);
    $column = $main->column('m');
    $column->textInput('first');
    $second = $column->textInput('second');
    $main->setInitialFocus($second);
    $main->present();
    pumpUntil(fn (): bool => $main->native()->isKeyWindow(), 3.0);
    pumpFor(0.2);

    expect($second->hasFocus())->toBeTrue()
        ->and($main->native()->initialFirstResponder())->toBe($second->native())
        ->and(focusMail())->toBe([]);
    $main->close();
});

it('posts nothing for the focus AppKit gives on present', function (): void {
    $main = focusWindow();
    pumpFor(0.2);

    expect(focusMail())->toBe([]);
    $main->close();
});

it('posts nothing when code hides or disables the focused input', function (string $change): void {
    $main = focusWindow();
    $first = $main->view('m.first');
    $first->focus();
    pumpFor(0.1);
    $change === 'hide' ? $first->hide() : $first->setEnabled(false);
    pumpFor(0.1);
    // An event after it: the tracker compares after each one.
    press(0);

    expect($first->hasFocus())->toBeFalse()
        ->and(focusMail())->toBe([]);
    $main->close();
})->with(['hide', 'disable']);

it('posts nothing when code hides or disables the container of the focused input', function (string $change): void {
    $main = driver()->open('main', 480, 320);
    $column = $main->column('m');
    $first = $column->row('r')->textInput('first');
    $column->textInput('second');
    $main->present();
    pumpUntil(fn (): bool => $main->native()->isKeyWindow(), 3.0);
    $first->focus();
    pumpFor(0.1);
    takeMail(session());
    $row = $main->view('m.r');
    $change === 'hide' ? $row->hide() : $row->setEnabled(false);
    pumpFor(0.1);
    press(A_LETTER);

    expect($first->hasFocus())->toBeFalse()
        ->and(focusMail())->toBe([]);
    $main->close();
})->with(['hide', 'disable']);

it('keeps a disabled focusable canvas out of focus, and lets go when it is disabled', function (string $disabled): void {
    $main = focusWindow();
    $canvas = $main->view('m.map')->setFocusable();
    $canvas->focus();
    pumpFor(0.1);
    takeMail(session());
    $main->view($disabled)->setEnabled(false);
    pumpFor(0.1);
    press(A_LETTER);

    expect($canvas->hasFocus())->toBeFalse()
        ->and($canvas->native()->acceptsFirstResponder())->toBeFalse()
        ->and(focusMail())->toBe([]);
    $main->close();
})->with(['itself' => 'm.map', 'container' => 'm']);

it('refuses an initial focus from another window', function (): void {
    $main = focusWindow();
    $other = driver()->open('other', 200, 100);
    $input = $other->column('o')->textInput('x');

    expect(fn () => $main->setInitialFocus($input))->toThrow(WindowException::class, "'o.x' is not in window 'main'.");
    $other->close();
    $main->close();
});

it('forgets a focused primitive that is removed, posting nothing', function (): void {
    $main = focusWindow();
    $first = $main->view('m.first');
    $first->focus();
    pumpFor(0.1);
    takeMail(session());
    $first->remove();
    pumpFor(0.1);
    press(A_LETTER);

    expect($main->focused())->not->toBe($first)
        ->and(focusMail())->toBe([]);
    $main->close();
});
