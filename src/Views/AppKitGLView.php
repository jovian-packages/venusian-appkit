<?php

namespace Jovian\Venusian\AppKit\Views;

use Jovian\Bindings\AppKit\NS\NSWindow;
use Jovian\Bindings\AppKit\Values\NSRect;
use Surface\Contracts\Drawing\Executor;
use Surface\Contracts\Drawing\GPUEngine;
use Surface\NativeWindows\Views\GPUView;
use Surface\NativeWindows\Windowable;

/**
 * A Surface GPU region over an NSOpenGLView whose context the surface
 * lends to the engine. This package never imports an OpenGL symbol. The
 * tick drives frames, exactly like the Metal twin.
 */
class AppKitGLView extends GPUView
{
    public function __construct(
        string $name,
        Windowable $windowable,
        GPUEngine $engine,
        Executor $executor,
        float $scale,
        public readonly AppKitGLSurface $surface,
        public readonly NSWindow $native_window,
    ) {
        parent::__construct($name, $windowable, $engine, $executor, $scale);
    }

    /** Invert y through layoutSpace(), reframe, tell the context, resize the executor in pixels. */
    protected function applyFrame(int $x, int $y, int $width, int $height): void
    {
        [, $content_height] = $this->layoutSpace();

        $this->surface->view->setFrame(new NSRect(
            (float) $x,
            (float) ($content_height - $y - $height),
            (float) $width,
            (float) $height,
        ));

        $scale = $this->native_window->backingScaleFactor();
        $this->setScale($scale);
        $this->surface->update();
        $this->executor->resize((int) round($width * $scale), (int) round($height * $scale));
    }

    protected function destroyNative(): void
    {
        $this->surface->view->removeFromSuperview();
    }

    protected function applyVisible(bool $visible): void
    {
        $this->surface->view->setHidden(! $visible);
    }

    /** Tick drives frames. */
    protected function queueNativeFrame(): void {}
}
