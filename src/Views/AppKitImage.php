<?php

namespace Jovian\Venusian\AppKit\Views;

use Jovian\Bindings\AppKit\NS\NSControl;
use Jovian\Bindings\AppKit\NS\NSImage;
use Jovian\Bindings\AppKit\NS\NSImageView;
use Jovian\Bindings\AppKit\QuartzCore\CALayer;
use Surface\Contracts\NativeWindows\Views\Color;
use Surface\Contracts\NativeWindows\WindowableException;
use Surface\NativeWindows\Views\Image;
use Surface\NativeWindows\Windowable;

/**
 * A Surface image over an NSImageView scaling proportionally up or down —
 * the frame is layout, the aspect ratio is the picture's.
 */
class AppKitImage extends Image
{
    use ComposesAppKitStyle;
    use TranslatesAppKitFrames;

    public function __construct(
        string $name,
        Windowable $window,
        ?string $path,
        public readonly NSImageView $image_view,
    ) {
        parent::__construct($name, $window, $path);
    }

    protected function control(): NSControl
    {
        return $this->image_view;
    }

    protected function applyPath(string $path): void
    {
        // Hold the box in a local until setImage has retained natively —
        // never chain ->handle off a temp.
        $ns_image = NSImage::initWithContentsOfFile($path);
        if (! $ns_image instanceof NSImage) {
            throw new WindowableException("AppKit could not read an image at '{$path}'.");
        }

        $this->image_view->setImage($ns_image->handle);
    }

    protected function applyBackground(Color $color): void
    {
        $this->image_view->setWantsLayer(true);
        $layer = $this->image_view->layer();
        if ($layer instanceof CALayer) {
            // The NSColor must outlive the call: its CGColor is raw pointer
            // bits into the colour object, and the layer only CFRetains once
            // setBackgroundColor executes.
            $ns_color = $this->nsColor($color);
            $layer->setBackgroundColor($ns_color->CGColor());
        }
    }
}
