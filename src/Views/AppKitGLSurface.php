<?php

namespace Jovian\Venusian\AppKit\Views;

use Jovian\Bindings\AppKit\NS\NSOpenGLContext;
use Jovian\Bindings\AppKit\NS\NSOpenGLPixelFormat;
use Jovian\Bindings\AppKit\NS\NSOpenGLView;
use Jovian\Venusian\AppKit\Exceptions\AppKitWindowException;
use Surface\Contracts\Drawing\GLSurface;

/**
 * The context lender. Holds the NSOpenGLView, its pixel format and its
 * context boxes for the view's life (temp-handle rule). It never draws:
 * the executor owns GL state, the twin owns placement.
 */
final class AppKitGLSurface implements GLSurface
{
    private ?NSOpenGLContext $context = null;

    private bool $context_has_view = false;

    public function __construct(
        public readonly NSOpenGLView $view,
        public readonly NSOpenGLPixelFormat $format,
    ) {}

    /**
     * A context with no view has no drawable. The view only joins the
     * context once it is in a window, so re-check until it has — once true
     * it stays true.
     */
    public function makeCurrent(): void
    {
        $context = $this->context();
        if (! $this->context_has_view) {
            $attached = $context->view();
            if (is_null($attached) || $attached->handle !== $this->view->handle) {
                $context->setView($this->view->handle);
                $attached = $context->view();
            }
            $this->context_has_view = ! is_null($attached) && $attached->handle === $this->view->handle;
        }
        $context->makeCurrentContext();
    }

    public function present(): void
    {
        $this->context()->flushBuffer();
    }

    /** Points × backing scale, straight from AppKit. */
    public function drawableSize(): array
    {
        $backing = $this->view->convertRectToBacking($this->view->bounds());

        return [max(1, (int) round($backing->width)), max(1, (int) round($backing->height))];
    }

    /** After a reframe the context must re-read its drawable. */
    public function update(): void
    {
        $this->context()->update();
    }

    private function context(): NSOpenGLContext
    {
        if (is_null($this->context)) {
            $context = $this->view->openGLContext();
            if (! $context instanceof NSOpenGLContext) {
                throw AppKitWindowException::viewMintFailed('opengl-context');
            }
            $this->context = $context;
        }

        return $this->context;
    }
}
