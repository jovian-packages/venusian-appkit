<?php

namespace Jovian\Venusian\AppKit\Stages;

use Jovian\Bindings\AppKit\NS\NSWindow;
use Jovian\Bindings\AppKit\Runtime\Delegate;
use Jovian\Venusian\AppKit\Views\AppKitAttachment;
use Surface\Contracts\Drawing\Executor;
use Surface\Contracts\Drawing\GPUEngine;
use Surface\Stage\StagedWindow;

/**
 * A stage over an NSWindow whose content view is the engine's surface (a
 * layer-backed NSView or an NSOpenGLView). The window, the attachment boxes
 * and the delegate are held for the stage's life.
 */
final class AppKitStagedWindow extends StagedWindow
{
    public function __construct(
        string $name,
        GPUEngine $engine,
        Executor $executor,
        int $width,
        int $height,
        float $scale,
        public readonly NSWindow $window,
        public readonly AppKitAttachment $attached,
        private readonly Delegate $delegate,
    ) {
        parent::__construct($name, $engine, $executor, $width, $height, $scale);
    }

    /** From windowDidResize: — read the content view in points, let a GL context re-read its drawable, resize. */
    public function nativeResized(): void
    {
        $frame = $this->attached->view->frame();
        $this->attached->gl?->update();
        $this->resized((int) round($frame->width), (int) round($frame->height), $this->window->backingScaleFactor());
    }

    protected function applyTitle(string $title): void
    {
        $this->window->setTitle($title);
    }

    protected function applyShow(): void
    {
        $this->window->makeKeyAndOrderFront(0);
    }

    /**
     * The executor is already released. Drop the delegate first so no
     * callback reaches a closed stage, and unregister both selectors: the
     * ext's process-global callable table otherwise pins the closures, and
     * through them this stage and its boxes, for the process's life.
     */
    protected function destroyNative(): void
    {
        $this->window->setDelegate(0);
        $this->delegate->off('windowShouldClose:');
        $this->delegate->off('windowDidResize:');
        $this->window->orderOut(0);
        $this->window->close();
    }
}
