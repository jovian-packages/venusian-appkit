<?php

namespace Jovian\Toolkits\Appkit\Primitives;

use Jovian\Toolkits\Appkit\Primitives\Concerns\AppkitPrimitive;
use NSImage;
use NSRect;
use NSView;
use Surface\Contracts\Windows\Primitives\ImageScaling;
use Surface\Contracts\Windows\Primitives\Placement;
use Surface\Contracts\Windows\ToolkitWindow;
use Surface\Contracts\Windows\WindowException;
use Surface\Windows\Primitives\TKImage;
use Surface\Windows\Primitives\TKPrimitiveGroup;

/**
 * A layer-backed view drawing the image as its layer contents, clipped to its bounds, with the
 * layer's contents gravity as the scaling: FIT resizeAspect, FILL resizeAspectFill (covers and
 * crops), CENTER center, STRETCH resize. NSImageView cannot cover: it draws in a sublayer of
 * its own and offers no aspect-fill scaling. The view's natural size is the image's.
 */
class AppkitImage extends TKImage
{
    use AppkitPrimitive;

    protected ?NSImage $image = null;

    /**
     * The file the view is showing; a refused setFile() leaves file() here.
     * @var string|null
     */
    protected ?string $shown = null;

    /**
     * @throws WindowException When $file cannot be read as an image.
     */
    public function __construct(string $name, ToolkitWindow $window, ?TKPrimitiveGroup $parent, Placement $placement, ?string $file)
    {
        parent::__construct($name, $window, $parent, $placement, $file);
        $view = $this->adoptNative(NSView::initWithFrame(new NSRect()));
        $view->setLayerMasksToBounds(true);
        $view->setLayerContentsGravity(self::gravity($this->scaling));
        $this->load($file);
    }

    /**
     * @param string|null $file
     * @return void
     * @throws WindowException When $file cannot be read; the shown image and file() stay as they were.
     */
    protected function applyFile(?string $file): void
    {
        try {
            $this->load($file);
        } catch (WindowException $refused) {
            $this->file = $this->shown;

            throw $refused;
        }
    }

    protected function applyScaling(ImageScaling $scaling): void
    {
        $this->native->setLayerContentsGravity(self::gravity($scaling));
    }

    protected function naturalLength(bool $horizontal): float
    {
        if (is_null($this->image)) {
            return 0.0;
        }
        $size = $this->image->size();

        return $horizontal ? $size->width : $size->height;
    }

    /**
     * @param string|null $file
     * @return void
     * @throws WindowException
     */
    protected function load(?string $file): void
    {
        $image = is_null($file) ? null : NSImage::initWithContentsOfFile($file);
        if (! is_null($file) && is_null($image)) {
            throw new WindowException("Image file '{$file}' cannot be read.");
        }

        $this->image = $image;
        $this->shown = $file;
        $this->native->setLayerContents($image);

        $this->remeasure();
    }

    protected static function gravity(ImageScaling $scaling): string
    {
        return match ($scaling) {
            ImageScaling::FIT => kCAGravityResizeAspect,
            ImageScaling::FILL => kCAGravityResizeAspectFill,
            ImageScaling::CENTER => kCAGravityCenter,
            ImageScaling::STRETCH => kCAGravityResize,
        };
    }
}
