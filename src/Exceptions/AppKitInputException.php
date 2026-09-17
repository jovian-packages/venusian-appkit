<?php

namespace Jovian\Venusian\AppKit\Exceptions;

use Surface\Contracts\HumanInput\HumanInputException;

/**
 * Input failures from the appkit engine. A HumanInputException, so a sketch
 * catches one type whichever engine it reads; the inherited named
 * constructors (notConnected, unsupportedButton, ...) answer this class.
 */
class AppKitInputException extends HumanInputException
{
    /**
     * A handle asked of the GameController source is not a live GCController.
     */
    public static function noSuchController(int $handle): static
    {
        return new static("Handle {$handle} is not an attached GameController controller.");
    }
}
