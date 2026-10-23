<?php

declare(strict_types=1);

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

it('refuses to open a missing path', function (string $path): void {
    expect(fn () => driver()->desktop()->open($path))->toThrow(InvalidArgumentException::class, $path);
})->with(['/nonexistent-c7', 'nonexistent-c7']);
