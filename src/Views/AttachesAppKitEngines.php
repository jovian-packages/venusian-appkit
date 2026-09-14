<?php

namespace Jovian\Venusian\AppKit\Views;

use Jovian\Bindings\AppKit\NS\NSOpenGLPixelFormat;
use Jovian\Bindings\AppKit\NS\NSOpenGLView;
use Jovian\Bindings\AppKit\NS\NSView;
use Jovian\Bindings\AppKit\Runtime\Bridge;
use Jovian\Bindings\AppKit\Runtime\ObjCObject;
use Jovian\Bindings\AppKit\Values\NSRect;
use Jovian\Venusian\AppKit\Enums\NSOpenGLPixelFormatAttribute;
use Jovian\Venusian\AppKit\Enums\NSOpenGLProfile;
use Jovian\Venusian\AppKit\Exceptions\AppKitWindowException;
use Surface\Contracts\Drawing\GPUEngineDriver;
use Surface\Contracts\Drawing\GPUHost;
use Surface\Contracts\Drawing\SurfaceKind;
use Throwable;

/**
 * Mint the NSView an engine's surface kind needs, attach the engine, wire
 * what comes back. Shared by GPU regions (window delegate) and stages
 * (stage session); the caller parents the view. Null means refused by
 * kind only — the caller throws its own refusal.
 */
trait AttachesAppKitEngines
{
    protected function attachEngine(string $name, GPUEngineDriver $driver, float $scale, int $width, int $height): ?AppKitAttachment
    {
        return match ($driver->surfaceKind()) {
            SurfaceKind::LAYER => $this->attachLayerEngine($name, $driver, $scale, $width, $height),
            SurfaceKind::GL_CONTEXT => $this->attachGLEngine($name, $driver, $scale, $width, $height),
            SurfaceKind::VULKAN_SURFACE, SurfaceKind::HOST_WINDOW => null,
        };
    }

    /** A plain NSView whose layer becomes the engine's adopted CAMetalLayer. An engine that hands back no layer is an error, not a refusal. */
    private function attachLayerEngine(string $name, GPUEngineDriver $driver, float $scale, int $width, int $height): AppKitAttachment
    {
        $view = NSView::initWithFrame(new NSRect(0.0, 0.0, (float) $width, (float) $height));
        if (! $view instanceof NSView) {
            throw AppKitWindowException::viewMintFailed($name);
        }

        $attachment = $driver->attach(new GPUHost(Bridge::pointerOf($view->handle), $width, $height, $scale));
        if ($attachment->layer_pointer <= 0) {
            $attachment->executor->release();
            throw $this->noLayerError($name, $driver->engine()->value);
        }

        $layer = ObjCObject::box(Bridge::adopt($attachment->layer_class, $attachment->layer_pointer));
        if (is_null($layer)) {
            $attachment->executor->release();
            throw AppKitWindowException::viewMintFailed($name);
        }

        $view->setWantsLayer(true);
        $view->setLayer($layer->handle);

        return new AppKitAttachment($view, $attachment, $layer);
    }

    /** The error for a LAYER engine that handed back no layer. A host whose callers catch another family (stages) overrides it. */
    protected function noLayerError(string $name, string $engine): Throwable
    {
        return AppKitWindowException::engineReturnedNoLayer($name, $engine);
    }

    /** 4.1 core, double-buffered, 24-bit colour, best-resolution surface: the drawable is points × scale. */
    private function attachGLEngine(string $name, GPUEngineDriver $driver, float $scale, int $width, int $height): AppKitAttachment
    {
        $format = NSOpenGLPixelFormat::initWithAttributes([
            NSOpenGLPixelFormatAttribute::OPENGL_PROFILE->value, NSOpenGLProfile::VERSION_4_1_CORE->value,
            NSOpenGLPixelFormatAttribute::DOUBLE_BUFFER->value,
            NSOpenGLPixelFormatAttribute::COLOR_SIZE->value, 24,
            0,
        ]);
        if (! $format instanceof NSOpenGLPixelFormat) {
            throw AppKitWindowException::viewMintFailed($name);
        }

        $view = NSOpenGLView::initWithFramePixelFormat(new NSRect(0.0, 0.0, (float) $width, (float) $height), $format->handle);
        if (! $view instanceof NSOpenGLView) {
            throw AppKitWindowException::viewMintFailed($name);
        }
        $view->setWantsBestResolutionOpenGLSurface(true);

        $gl = new AppKitGLSurface($view, $format);
        $attachment = $driver->attach(new GPUHost(Bridge::pointerOf($view->handle), $width, $height, $scale, $gl));

        return new AppKitAttachment($view, $attachment, null, $gl);
    }
}
