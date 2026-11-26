<?php

namespace Jovian\Toolkits\Appkit\UI;

use NSRect;
use NSView;
use NSImage;
use NSLayoutConstraint;
use Surface\Toolkits\UI\TKImage;
use Surface\Toolkits\ToolkitException;
use Surface\Contracts\Toolkits\UI\ImageScaling;
use Jovian\Toolkits\Appkit\UI\Concerns\AppkitNative;
use Jovian\Toolkits\Appkit\UI\Concerns\TakesInputFromAbove;

/**
 * A view whose layer shows the picture: NSImageView cannot cover. Its natural size is the
 * picture's, just above the stack's pull to zero (485). A FIT picture's natural height is its
 * width times the picture's height over width (NSLayoutDimension constraintEqualToAnchor:
 * multiplier:, at 485), so it follows the width it is given in the same layout pass.
 *
 * In an overlay, a picture that fills both ways has no natural size: it covers the overlay and
 * never sizes it, so a 3000-point picture does not grow the overlay or the window. Its layer
 * always masks to its bounds (rounded by its corners), whatever clip() says.
 */
class AppkitImage extends TKImage
{
    use AppkitNative {
        releaseNative as private releaseView;
    }
    use TakesInputFromAbove;

    private ?NSImage $image = null;

    /** @var list<NSLayoutConstraint> */
    private array $natural = [];

    protected function bootstrapNative(): void
    {
        $file = $this->file();
        $image = is_null($file) ? null : self::read($file);

        $view = NSView::initWithFrame(new NSRect(0.0, 0.0, 0.0, 0.0));
        $view->setWantsLayer(true);
        $view->setClipsToBounds(true);
        $this->adoptNative($view);

        $this->showPicture($image);
        $this->applyScaling($this->scaling());
    }

    protected function applyFile(?string $file): void
    {
        $this->showPicture(is_null($file) ? null : self::read($file));
    }

    protected function applyScaling(ImageScaling $scaling): void
    {
        $this->native()->layer()?->setContentsGravity(match ($scaling) {
            ImageScaling::FIT => kCAGravityResizeAspect,
            ImageScaling::FILL => kCAGravityResizeAspectFill,
            ImageScaling::CENTER => kCAGravityCenter,
            ImageScaling::STRETCH => kCAGravityResize,
        });
        $this->measure();
    }

    /**
     * Always clipped, whatever clip() says: a covering picture is larger than the view.
     */
    protected function masksContent(): bool
    {
        return true;
    }

    /**
     * Inside an overlay, a picture that fills both ways takes the overlay's size and gives it
     * none: its natural size is dropped.
     */
    protected function applyFill(bool $horizontal, bool $vertical): void
    {
        $this->measure();
    }

    /** It takes no input, so it has nothing to disable. */
    protected function applyNativeEnabled(bool $takes_input): void {}

    protected function releaseNative(): void
    {
        NSLayoutConstraint::deactivateConstraints($this->natural);
        $this->natural = [];
        $this->releaseView();
    }

    private function showPicture(?NSImage $image): void
    {
        $this->image = $image;
        $this->native()->layer()?->setContents($image);
        $this->measure();
    }

    private function measure(): void
    {
        NSLayoutConstraint::deactivateConstraints($this->natural);
        $this->natural = [];
        if (is_null($this->image) || $this->coversOverlay()) {
            return;
        }

        $size = $this->image->size();
        $view = $this->native();
        $width = $view->widthAnchor()->constraintEqualToConstant($size->width);
        $height = $this->scaling() === ImageScaling::FIT && $size->width > 0.0
            ? $view->heightAnchor()->constraintEqualToAnchorMultiplier($view->widthAnchor(), $size->height / $size->width)
            : $view->heightAnchor()->constraintEqualToConstant($size->height);
        $this->natural = [$width, $height];
        foreach ($this->natural as $constraint) {
            $constraint->setPriority(485.0);
        }
        NSLayoutConstraint::activateConstraints($this->natural);
    }

    /**
     * In an overlay, filling both ways: sized by the overlay alone.
     */
    private function coversOverlay(): bool
    {
        return $this->parent() instanceof AppkitOverlay && $this->fills() === [true, true];
    }

    /**
     * @throws ToolkitException When AppKit reads no picture in it.
     */
    private static function read(string $file): NSImage
    {
        return NSImage::initWithContentsOfFile($file) ?? throw new ToolkitException("'{$file}' is not an image AppKit reads.");
    }
}
