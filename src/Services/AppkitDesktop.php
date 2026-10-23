<?php

namespace Jovian\Toolkits\Appkit\Services;

use InvalidArgumentException;
use NSURL;
use NSWorkspace;
use Surface\Contracts\Windows\Services\Desktop;

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
}
