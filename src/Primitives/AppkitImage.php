<?php

namespace Jovian\Toolkits\Appkit\Primitives;

use Jovian\Toolkits\Appkit\Primitives\Concerns\AppkitPrimitive;
use NSImage;
use NSNotificationCenter;
use NSOperationQueue;
use NSRect;
use NSTimer;
use NSView;
use ObjCTarget;
use WeakReference;
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

    /** The width the view was last given: a FIT image's natural height follows it. 0 before the first. */
    protected float $fit_width = 0.0;

    /** Watches the view's frame for the width a FIT image's natural height follows. */
    protected ?object $fit_observer = null;

    /** The re-measure asked for after a width change, while it waits for the next run-loop turn. */
    protected ?array $refit = null;

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
        $view = $this->adoptNative(NSView::initWithFrame(new NSRect(0.0, 0.0, 0.0, 0.0)));
        $view->setWantsLayer(true);
        $view->layer()?->setMasksToBounds(true);
        $view->layer()?->setContentsGravity(self::gravity($this->scaling));
        $view->setPostsFrameChangedNotifications(true);
        $this->fit_observer = NSNotificationCenter::defaultCenter()->addObserverForNameObjectQueueUsingBlock(
            NSViewFrameDidChangeNotification,
            $view,
            NSOperationQueue::mainQueue(),
            fn () => $this->widthChanged(),
        );
        $this->load($file);
    }

    /**
     * A FIT image's natural height follows the width it was given, at the image's aspect, as
     * GtkPicture measures height for width: a wide slot no longer asks for the file's full height
     * and shows the picture centred in a band of empty space. ext-appkit binds no anchor
     * multiplier, so the height is measured again when the width changes; only the height moves,
     * so the next frame change finds the same width and stops.
     */
    protected function widthChanged(): void
    {
        $width = $this->native->frame()->width;
        if (abs($width - $this->fit_width) < 0.5) {
            return;
        }
        $this->fit_width = $width;
        if ($this->scaling !== ImageScaling::FIT || ! is_null($this->refit)) {
            return;
        }
        // The frame changes inside a layout pass, and a constraint swapped there does not move the
        // view: the re-measure runs on the next run-loop turn, as the collection view's reload does.
        $self = WeakReference::create($this);
        $target = new ObjCTarget(static function () use ($self): void {
            $image = $self->get();
            if (! is_null($image)) {
                $image->refit = null;
                $image->removed || $image->remeasure();
            }
        });
        $this->refit = [NSTimer::scheduledTimerWithTimeIntervalTargetSelectorUserInfoRepeats(0.0, $target, ObjCTarget::ACTION, null, false), $target];
    }

    protected function releaseNative(): void
    {
        is_null($this->refit) || $this->refit[0]->invalidate();
        $this->refit = null;
        if (! is_null($this->fit_observer)) {
            NSNotificationCenter::defaultCenter()->removeObserver($this->fit_observer);
            $this->fit_observer = null;
        }
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
        $this->native->setWantsLayer(true);
        $this->native->layer()?->setContentsGravity(self::gravity($scaling));
        $this->remeasure();
    }

    protected function naturalLength(bool $horizontal): float
    {
        if (is_null($this->image)) {
            return 0.0;
        }
        $size = $this->image->size();
        if (! $horizontal && $this->scaling === ImageScaling::FIT && $this->fit_width > 0.0 && $size->width > 0.0) {
            return $this->fit_width * $size->height / $size->width;
        }

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
        $this->native->setWantsLayer(true);
        $this->native->layer()?->setContents($image);

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
