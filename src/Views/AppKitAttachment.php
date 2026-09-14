<?php

namespace Jovian\Venusian\AppKit\Views;

use Jovian\Bindings\AppKit\NS\NSView;
use Jovian\Bindings\AppKit\Runtime\ObjCObject;
use Surface\Contracts\Drawing\GPUAttachment;

/**
 * What AppKit minted for an engine and what the engine handed back. The
 * boxes are held here for the host's life — never chain ->handle off a temp.
 * Exactly one of layer (LAYER) or gl (GL_CONTEXT) is set.
 */
final readonly class AppKitAttachment
{
    public function __construct(
        public NSView $view,
        public GPUAttachment $attachment,
        public ?ObjCObject $layer = null,
        public ?AppKitGLSurface $gl = null,
    ) {}
}
