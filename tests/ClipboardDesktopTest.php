<?php

declare(strict_types=1);

use Surface\Contracts\Windows\Mail\Services\ClipboardChanged;
use Surface\Contracts\Windows\Mail\Services\ClipboardText;

// The clipboard test writes the user's clipboard: it keeps the text there and puts it back.

beforeEach(function (): void {
    $this->kept = NSPasteboard::generalPasteboard()->stringForType(NSPasteboardTypeString);
});

afterEach(function (): void {
    $board = NSPasteboard::generalPasteboard();
    $board->clearContents();
    if (! is_null($this->kept)) {
        $board->setStringForType($this->kept, NSPasteboardTypeString);
    }
});

it('puts text on the general pasteboard', function (): void {
    driver()->clipboard()->setText('c7 path');

    expect(NSPasteboard::generalPasteboard()->stringForType(NSPasteboardTypeString))->toBe('c7 path');
});

/** The clipboard mail the session holds after a pump, in order. */
function clipboardMail(): array
{
    pumpFor(0.05);

    return array_values(array_filter(takeMail(session()), fn (object $mail): bool => $mail instanceof ClipboardText || $mail instanceof ClipboardChanged));
}

it('answers a text request with the pasteboard\'s text, once', function (): void {
    driver()->clipboard()->setText('x');
    clipboardMail();
    $id = driver()->clipboard()->requestText();

    expect(driver()->clipboard()->hasText())->toBeTrue()
        ->and(clipboardMail())->toEqual([new ClipboardText($id, 'x')]);
});

it('answers null and has no text when the pasteboard holds none', function (): void {
    NSPasteboard::generalPasteboard()->clearContents();
    $id = driver()->clipboard()->requestText();

    expect(driver()->clipboard()->hasText())->toBeFalse()
        ->and(array_values(array_filter(clipboardMail(), fn (object $mail): bool => $mail instanceof ClipboardText)))->toEqual([new ClipboardText($id, null)]);
});

it('posts ClipboardChanged for its own text', function (): void {
    clipboardMail();
    driver()->clipboard()->setText('c14 own');

    expect(clipboardMail())->toEqual([new ClipboardChanged()]);
});

it('posts ClipboardChanged when a window becomes key after another app copied', function (): void {
    driver()->closeAll();
    $first = driver()->open('first', 300, 200);
    $second = driver()->open('second', 300, 200);
    $first->present();
    pumpFor(0.2);
    $second->present();
    pumpFor(0.2);
    clipboardMail();
    shell_exec('printf c14-other | pbcopy');
    $first->present();
    pumpUntil(fn (): bool => $first->native()->isKeyWindow(), 2.0);

    expect(clipboardMail())->toEqual([new ClipboardChanged()]);

    $second->present();
    pumpFor(0.2);
    $first->present();
    pumpUntil(fn (): bool => $first->native()->isKeyWindow(), 2.0);

    expect(clipboardMail())->toBe([]);
    driver()->closeAll();
});

it('refuses to open a missing path', function (string $path): void {
    expect(fn () => driver()->desktop()->open($path))->toThrow(InvalidArgumentException::class, $path);
})->with(['/nonexistent-c7', 'nonexistent-c7']);
