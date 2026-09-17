<?php

namespace Jovian\Venusian\AppKit\Input;

/** The native windows mouse events land in: windowNumber → name, the content size (y flip, inside test). */
interface WindowSpace
{
    /** @return array<int, array{name: string, content_width: float, content_height: float}> keyed by windowNumber */
    public function windows(): array;

    /** setAcceptsMouseMovedEvents(true) on every window not yet done. */
    public function acceptMouseMoves(): void;
}
