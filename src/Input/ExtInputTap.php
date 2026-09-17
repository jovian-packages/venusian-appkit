<?php

namespace Jovian\Venusian\AppKit\Input;

use Jovian\Bindings\AppKit\Runtime\Bridge;

/**
 * The ext's local NSEvent monitor. It records while NSApp pumps (main
 * thread, inside Bridge::pump). It passes every event on, except keys in the
 * listed windows that no view there can take (swallowKeysIn).
 */
final class ExtInputTap implements InputTap
{
    public function watch(int $mask): void
    {
        Bridge::watchInput($mask);
    }

    public function drain(): array
    {
        return Bridge::drainInput();
    }

    public function swallowKeysIn(array $windowNumbers): void
    {
        Bridge::swallowKeysIn($windowNumbers);
    }
}
