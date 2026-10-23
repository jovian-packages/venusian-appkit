<?php

namespace Jovian\Toolkits\Appkit\Services;

use NSPasteboard;
use Surface\Contracts\Windows\Services\Clipboard;

final class AppkitClipboard implements Clipboard
{
    public function setText(string $text): void
    {
        $board = NSPasteboard::generalPasteboard();
        $board->clearContents();
        $board->setStringForType($text, NSPasteboardTypeString);
    }
}
