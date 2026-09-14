<?php

namespace Jovian\Venusian\AppKit\Views;

use Jovian\Bindings\AppKit\NS\NSView;
use Jovian\Bindings\AppKit\NS\NSWindow;
use Jovian\Bindings\AppKit\Runtime\ObjCObject;
use Jovian\Bindings\AppKit\Values\NSRect;
use Surface\Contracts\Drawing\Executor;
use Surface\Contracts\Drawing\GPUEngine;
use Surface\NativeWindows\Views\GPUView;
use Surface\NativeWindows\Windowable;

/**
 * A Surface GPU region over a plain NSView whose layer is an adopted
 * CAMetalLayer. This package never imports a Metal symbol — the layer
 * arrives as pointer bits, is adopted here, and is held as an ObjCObject
 * for the view's life (the menu-bar Heisenbug: never chain ->handle off
 * a temp). Surface's GPUView::remove() releases the executor first;
 * destroyNative only lifts the view.
 */
class AppKitGPUView extends GPUView
{
    public function __construct(
        string $name,
        Windowable $windowable,
        GPUEngine $engine,
        Executor $executor,
        float $scale,
        public readonly NSView $view,
        public readonly ObjCObject $layer,
        public readonly NSWindow $native_window,
    ) {
        parent::__construct($name, $windowable, $engine, $executor, $scale);
    }

    /**
     * Invert y through layoutSpace() like TranslatesAppKitViewFrames, then
     * refresh the backing scale and resize the executor in pixels.
     */
    protected function applyFrame(int $x, int $y, int $width, int $height): void
    {
        [, $content_height] = $this->layoutSpace();

        $this->view->setFrame(new NSRect(
            (float) $x,
            (float) ($content_height - $y - $height),
            (float) $width,
            (float) $height,
        ));

        $scale = $this->native_window->backingScaleFactor();
        $this->setScale($scale);
        $this->executor->resize((int) round($width * $scale), (int) round($height * $scale));
    }

    protected function destroyNative(): void
    {
        $this->view->removeFromSuperview();
    }

    protected function applyVisible(bool $visible): void
    {
        $this->view->setHidden(! $visible);
    }

    /** Tick drives frames; a self-driving twin would queue natively here. */
    protected function queueNativeFrame(): void {}
}
