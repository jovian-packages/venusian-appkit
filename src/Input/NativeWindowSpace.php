<?php

namespace Jovian\Venusian\AppKit\Input;

use Jovian\Bindings\AppKit\NS\NSView;
use Jovian\Bindings\AppKit\NS\NSWindow;
use Jovian\Venusian\AppKit\Stages\AppKitStagedWindow;
use Jovian\Venusian\AppKit\Windows\AppKitWindowDelegate;

/**
 * The live windows this package minted: native windows from the
 * `native-window` manager, plus AppKit stages when `stages` is bound.
 * Resolved through the container on every call, so windows opened after the
 * engine connected are seen.
 */
final class NativeWindowSpace implements WindowSpace
{
    /** @var array<int, true> NSWindow handles already accepting mouse-moved events */
    private array $accepting = [];

    public function windows(): array
    {
        $windows = [];

        foreach ($this->hosts() as [$name, $window, $content]) {
            $frame = $content->frame();
            $windows[$window->windowNumber()] = [
                'name' => $name,
                'content_width' => $frame->width,
                'content_height' => $frame->height,
            ];
        }

        return $windows;
    }

    public function acceptMouseMoves(): void
    {
        $seen = [];

        foreach ($this->hosts() as [, $window]) {
            $seen[$window->handle] = true;

            if (! array_key_exists($window->handle, $this->accepting)) {
                $window->setAcceptsMouseMovedEvents(true);
            }
        }

        $this->accepting = $seen;
    }

    /** @return list<array{0: string, 1: NSWindow, 2: NSView}> name, window, content view */
    private function hosts(): array
    {
        $hosts = [];
        $app = app();

        if ($app->bound('native-window')) {
            foreach (app('native-window')->driver()->all() as $window) {
                if ($window instanceof AppKitWindowDelegate) {
                    $hosts[] = [$window->name(), $window->window, $window->content];
                }
            }
        }

        if ($app->bound('stages')) {
            foreach (app('stages')->all() as $stage) {
                if ($stage instanceof AppKitStagedWindow) {
                    $hosts[] = [$stage->name(), $stage->window, $stage->attached->view];
                }
            }
        }

        return $hosts;
    }
}
