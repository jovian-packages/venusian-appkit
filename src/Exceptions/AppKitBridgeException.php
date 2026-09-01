<?php

namespace Jovian\Venusian\AppKit\Exceptions;

use Surface\Contracts\Bridge\BridgeException;

/**
 * Raised when the AppKit bridge cannot be stood up.
 */
class AppKitBridgeException extends BridgeException
{
    /**
     * The shared application lookup answered with something that is not an NSApplication.
     * @return static
     */
    public static function unknownNSApplicationResponse(): static
    {
        return new static("NSApplication::sharedApplication() did not yield an application.");
    }
}
