<?php

namespace Jovian\Venusian\AppKit\Exceptions;

use Surface\Contracts\Stage\StageException;
use Throwable;

/**
 * Raised when the appkit stage host cannot mint a stage. A StageException,
 * so a sketch catches one type whichever host it opened on; the inherited
 * named constructors (unsupported, attachFailed, ...) answer this class.
 */
class AppKitStageException extends StageException
{
    /**
     * AppKit answered with something that is not a window for this stage.
     */
    public static function windowMintFailed(string $name): static
    {
        return new static("AppKit did not create the window for stage '{$name}'.");
    }

    /**
     * A LAYER engine attached to this stage but handed back no layer to adopt.
     */
    public static function engineReturnedNoLayer(string $name, string $engine): static
    {
        return new static("The '{$engine}' engine returned no layer for stage '{$name}'.");
    }

    /**
     * AppKit could not mint the surface view (or adopt its layer) for this stage.
     */
    public static function viewMintFailed(string $name, Throwable $previous): static
    {
        return new static("AppKit could not mint the surface view for stage '{$name}'.", 0, $previous);
    }

    /**
     * The engine attached, but AppKit failed wiring its surface into the stage window.
     */
    public static function windowSetupFailed(string $name, Throwable $previous): static
    {
        return new static("AppKit could not set up the window for stage '{$name}'.", 0, $previous);
    }
}
