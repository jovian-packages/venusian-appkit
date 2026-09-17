<?php

namespace Venusian\AppKit\Tests\Support;

use Jovian\Venusian\AppKit\Input\InputTap;

final class FakeInputTap implements InputTap
{
    /** @var list<int> every mask watch() was given, in order */
    public array $masks = [];

    /** @var list<array<string, mixed>> records the next drain() hands over */
    public array $queue = [];

    public function watch(int $mask): void
    {
        $this->masks[] = $mask;
    }

    /** @var list<list<int>> every window list swallowKeysIn() was given */
    public array $swallowed = [];

    public function swallowKeysIn(array $windowNumbers): void
    {
        $this->swallowed[] = $windowNumbers;
    }

    public function drain(): array
    {
        $records = $this->queue;
        $this->queue = [];

        return $records;
    }
}
