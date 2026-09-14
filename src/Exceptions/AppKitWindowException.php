<?php

namespace Jovian\Venusian\AppKit\Exceptions;

use Surface\Contracts\NativeWindows\WindowableException;

/**
 * Raised when the AppKit bridge cannot be stood up.
 */
class AppKitWindowException extends WindowableException
{
    /**
     *
     * @return static
     */
    public static function couldNotMint(string $name): static
    {
        return new static("NSApplication::provisionNewWindow() did not yield an window delegate.");
    }

    public static function noContentView(string $name): static
    {
        return new static("NSView was expected content in Window '{$name}'.");
    }

    /**
     * AppKit answered with something that is not the menu or item asked for.
     * @param string $label
     * @return static
     */
    public static function menuMintFailed(string $label): static
    {
        return new static("AppKit did not mint a menu node for '{$label}'.");
    }

    /**
     * A LAYER engine attached but handed back no layer to adopt.
     */
    public static function engineReturnedNoLayer(string $name, string $engine): static
    {
        return new static("The '{$engine}' engine returned no layer for '{$name}'.");
    }

    /**
     * AppKit answered with something that is not the view asked for.
     */
    public static function viewMintFailed(string $name): static
    {
        return new static("AppKit did not mint a view for '{$name}'.");
    }
}
