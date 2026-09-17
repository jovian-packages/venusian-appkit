<?php

namespace Venusian\AppKit\Tests\Support;

use Jovian\Venusian\AppKit\Input\WindowSpace;

final class FakeWindowSpace implements WindowSpace
{
    public int $accept_calls = 0;

    /** @param array<int, array{name: string, content_width: float, content_height: float}> $windows */
    public function __construct(public array $windows = []) {}

    public function windows(): array
    {
        return $this->windows;
    }

    public function acceptMouseMoves(): void
    {
        $this->accept_calls++;
    }
}
