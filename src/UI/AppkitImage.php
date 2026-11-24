<?php

namespace Jovian\Toolkits\Appkit\UI;

use NSRect;
use NSView;
use NSImage;
use NSTimer;
use ObjCTarget;
use WeakReference;
use NSOperationQueue;
use NSLayoutConstraint;
use NSNotificationCenter;
use Surface\Toolkits\UI\TKImage;
use Surface\Toolkits\ToolkitException;
use Surface\Contracts\Toolkits\UI\ImageScaling;
use Jovian\Toolkits\Appkit\UI\Concerns\AppkitNative;
use Jovian\Toolkits\Appkit\UI\Concerns\TakesInputFromAbove;

/**
 * A view whose layer shows the picture: NSImageView cannot cover. Its natural size is the
 * picture's, just above the stack's pull to zero. A FIT picture's natural height follows the
 * width it was given; ext-appkit binds no aspect constraint, so that is set again, on the
 * next turn of the run loop, when the width changes.
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

    private float $fit_width = 0.0;

    private ?object $watching = null;

    /** @var array{NSTimer, ObjCTarget}|null */
    private ?array $refit = null;

    protected function bootstrapNative(): void
    {
        $file = $this->file();
        $image = is_null($file) ? null : self::read($file);

        $view = NSView::initWithFrame(new NSRect(0.0, 0.0, 0.0, 0.0));
        $view->setWantsLayer(true);
        $view->layer()?->setMasksToBounds(true);
        $view->setPostsFrameChangedNotifications(true);
        $this->adoptNative($view);

        $self = WeakReference::create($this);
        $this->watching = NSNotificationCenter::defaultCenter()->addObserverForNameObjectQueueUsingBlock(
            NSViewFrameDidChangeNotification,
            $view,
            NSOperationQueue::mainQueue(),
            static function () use ($self): void {
                $self->get()?->widthChanged();
            },
        );

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

    /** Rounded, and clipped: a covering picture is larger than the view. */
    protected function applyCornerRadius(float $radius): void
    {
        $layer = $this->native()->layer();
        $layer?->setCornerRadius($radius);
        $layer?->setMasksToBounds(true);
    }

    /** It takes no input, so it has nothing to disable. */
    protected function applyNativeEnabled(bool $takes_input): void {}

    protected function releaseNative(): void
    {
        if (! is_null($this->watching)) {
            NSNotificationCenter::defaultCenter()->removeObserver($this->watching);
            $this->watching = null;
        }
        if (! is_null($this->refit)) {
            $this->refit[0]->invalidate();
            $this->refit = null;
        }
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
        if (is_null($this->image)) {
            return;
        }

        $size = $this->image->size();
        $height = $this->scaling() === ImageScaling::FIT && $this->fit_width > 0.0 && $size->width > 0.0
            ? $this->fit_width * $size->height / $size->width
            : $size->height;

        $view = $this->native();
        $this->natural = [$view->widthAnchor()->constraintEqualToConstant($size->width), $view->heightAnchor()->constraintEqualToConstant($height)];
        foreach ($this->natural as $constraint) {
            $constraint->setPriority(485.0);
        }
        NSLayoutConstraint::activateConstraints($this->natural);
    }

    /**
     * The frame changes inside a layout pass, where a constraint swapped moves nothing.
     */
    private function widthChanged(): void
    {
        if ($this->isRemoved()) {
            return;
        }
        $width = $this->native()->frame()->width;
        if (abs($width - $this->fit_width) < 0.5) {
            return;
        }
        $this->fit_width = $width;
        if ($this->scaling() !== ImageScaling::FIT || ! is_null($this->refit)) {
            return;
        }

        $self = WeakReference::create($this);
        $target = new ObjCTarget(static function () use ($self): void {
            $image = $self->get();
            if (! is_null($image) && ! $image->isRemoved()) {
                $image->refit = null;
                $image->measure();
            }
        });
        $this->refit = [NSTimer::scheduledTimerWithTimeIntervalTargetSelectorUserInfoRepeats(0.0, $target, ObjCTarget::ACTION, null, false), $target];
    }

    /**
     * @throws ToolkitException When AppKit reads no picture in it.
     */
    private static function read(string $file): NSImage
    {
        return NSImage::initWithContentsOfFile($file) ?? throw new ToolkitException("'{$file}' is not an image AppKit reads.");
    }
}
