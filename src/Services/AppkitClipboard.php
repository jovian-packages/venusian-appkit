<?php

namespace Jovian\Toolkits\Appkit\Services;

use Jovian\Toolkits\Appkit\Bridge\AppkitSession;
use NSPasteboard;
use Surface\Contracts\Windows\Mail\Services\ClipboardChanged;
use Surface\Windows\Services\ToolkitClipboard;
use Voyager\Contracts\Signals\NamedSignal;

/**
 * The general pasteboard. AppKit has no change notification: a window of the app becoming key
 * compares the pasteboard's change count with the last one seen, and setText() posts at once.
 */
final class AppkitClipboard extends ToolkitClipboard
{
    private int $count;

    public function __construct(private readonly AppkitSession $session)
    {
        $this->count = NSPasteboard::generalPasteboard()->changeCount();
    }

    public function setText(string $text): void
    {
        $board = NSPasteboard::generalPasteboard();
        $board->clearContents();
        $board->setStringForType($text, NSPasteboardTypeString);
        $this->count = $board->changeCount();
        $this->changed();
    }

    public function hasText(): bool
    {
        return ! is_null(NSPasteboard::generalPasteboard()->availableTypeFromArray([NSPasteboardTypeString]));
    }

    /** A window of the app became key: post ClipboardChanged when the count moved since the last look. */
    public function compare(): void
    {
        $count = NSPasteboard::generalPasteboard()->changeCount();
        if ($count !== $this->count) {
            $this->count = $count;
            $this->changed();
        }
    }

    protected function readText(string $id): void
    {
        $this->answerText($id, NSPasteboard::generalPasteboard()->stringForType(NSPasteboardTypeString));
    }

    protected function post(NamedSignal $mail): void
    {
        $this->session->post($mail);
    }

    private function changed(): void
    {
        $this->session->postLatest('clipboard.changed', new ClipboardChanged());
    }
}
