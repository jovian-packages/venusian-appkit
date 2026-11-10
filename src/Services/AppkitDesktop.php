<?php

namespace Jovian\Toolkits\Appkit\Services;

use InvalidArgumentException;
use NSURL;
use NSWorkspace;
use Surface\Contracts\Windows\Services\Desktop;
use Surface\Windows\Services\WebAddress;

final class AppkitDesktop implements Desktop
{
    public function open(string $path): void
    {
        $full = realpath($path);
        if ($full === false) {
            throw new InvalidArgumentException("Nothing at {$path} to open.");
        }
        NSWorkspace::sharedWorkspace()->openURL(NSURL::fileURLWithPath($full));
    }

    public function browse(string $url): void
    {
        $address = NSURL::URLWithString(WebAddress::checked($url))
            ?? throw new InvalidArgumentException("{$url} is not a web address AppKit reads.");
        NSWorkspace::sharedWorkspace()->openURL($address);
    }
}
