<?php

namespace Jovian\Venusian\AppKit\Input;

/**
 * The NSEvent tap the engine reads. Records are buffered while NSApp pumps
 * and handed over oldest first.
 */
interface InputTap
{
    /** Start recording the NSEventMask bits in $mask; 0 stops recording. */
    public function watch(int $mask): void;

    /**
     * Take every record buffered since the last drain.
     *
     * @return list<array<string, mixed>>
     */
    public function drain(): array;

    /**
     * Consume unclaimed keys (after recording) in these windows, so AppKit
     * does not beep for a key no view takes. An empty list consumes nothing.
     *
     * @param list<int> $windowNumbers
     */
    public function swallowKeysIn(array $windowNumbers): void;
}
