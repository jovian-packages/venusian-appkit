<?php

namespace Jovian\Toolkits\Appkit\Primitives;

use Jovian\Toolkits\Appkit\Primitives\Concerns\AppkitPrimitive;
use NSLayoutConstraint;
use NSNotificationCenter;
use NSObject;
use NSOperationQueue;
use NSRect;
use NSView;
use Surface\Contracts\Windows\Primitives\Placement;
use Surface\Contracts\Windows\Primitives\TKPrimitive as Child;
use Surface\Contracts\Windows\ToolkitWindow;
use Surface\Windows\Primitives\TKFixed;
use Surface\Windows\Primitives\TKPrimitiveGroup;

/**
 * A plain NSView whose children sit at frames. Frames are top-left based; AppKit's origin is
 * bottom-left, so each child's y is flipped against the fixed's height, and every child is
 * framed again whenever the fixed's own frame changes. Its natural size is the extent of the
 * frames, as GtkFixed's is. A child is framed at least at its minimum size and at what its own
 * content requires (its fitting size), as GtkFixed allocates at least a child's minimum.
 */
class AppkitFixed extends TKFixed
{
    use AppkitPrimitive;

    /**
     * Width/height floors at the children's extent.
     * @var list<NSLayoutConstraint>
     */
    protected array $extent = [];

    protected ?NSObject $frame_observer = null;

    public function __construct(string $name, ToolkitWindow $window, ?TKPrimitiveGroup $parent, Placement $placement)
    {
        parent::__construct($name, $window, $parent, $placement);
        $view = $this->adoptNative(NSView::initWithFrame(new NSRect()));
        $view->setPostsFrameChangedNotifications(true);
        $this->frame_observer = NSNotificationCenter::defaultCenter()->addObserverForNameObjectQueueUsingBlock(
            NSViewFrameDidChangeNotification,
            $view,
            NSOperationQueue::mainQueue(),
            fn () => $this->reframe(),
        );
    }

    /**
     * Children keep their frames through autoresizing, not constraints.
     *
     * @param Child $child
     * @return void
     */
    protected function insertNative(Child $child): void
    {
        $view = self::nativeOf($child);
        $view->setTranslatesAutoresizingMaskIntoConstraints(true);
        $this->native->addSubview($view);
        $this->placeChild($child);
        $child->syncNativeEnabled();
        $this->extend();
    }

    protected function applyMove(Child $child, int $x, int $y): void
    {
        $this->refitChild($child);
    }

    protected function applyResize(Child $child, int $width, int $height): void
    {
        $this->refitChild($child);
    }

    /**
     * Frame the child again and re-measure the extent: after a move, a resize or a new minimum size.
     *
     * @param Child $child
     * @return void
     */
    public function refitChild(Child $child): void
    {
        $this->placeChild($child);
        $this->extend();
    }

    /**
     * Frame the child at its current frame, at least its required size, y flipped to AppKit's origin.
     *
     * @param Child $child
     * @return void
     */
    public function placeChild(Child $child): void
    {
        $frame = $this->frameOf($child);
        [$width, $height] = $this->framedSize($child);
        $top = $this->native->frame()->height;

        self::nativeOf($child)->setFrame(new NSRect((float) $frame->x, $top - $frame->y - $height, $width, $height));
    }

    /**
     * The child's frame size, widened to its minimum size and to its own fitting size.
     *
     * @param Child $child
     * @return array{float, float}
     */
    protected function framedSize(Child $child): array
    {
        $frame = $this->frameOf($child);
        [$min_width, $min_height] = $child->min_size ?? [0, 0];
        $fitting = self::nativeOf($child)->fittingSize();

        return [
            max((float) $frame->width, (float) $min_width, $fitting->width),
            max((float) $frame->height, (float) $min_height, $fitting->height),
        ];
    }

    /**
     * @param Child $child
     * @return void
     */
    public function removeNative(Child $child): void
    {
        $this->extend();
    }

    protected function releaseNative(): void
    {
        if (! is_null($this->frame_observer)) {
            NSNotificationCenter::defaultCenter()->removeObserver($this->frame_observer);
            $this->frame_observer = null;
        }
    }

    protected function reframe(): void
    {
        foreach ($this->children as $child) {
            if (! $child->isRemoved()) {
                $this->placeChild($child);
            }
        }
    }

    /**
     * Floors at the right and bottom edge of the furthest live frame.
     * @return void
     */
    protected function extend(): void
    {
        $right = 0;
        $bottom = 0;
        foreach ($this->children as $child) {
            if ($child->isRemoved()) {
                continue;
            }
            $frame = $this->frameOf($child);
            [$width, $height] = $this->framedSize($child);
            $right = max($right, $frame->x + $width);
            $bottom = max($bottom, $frame->y + $height);
        }

        NSLayoutConstraint::deactivateConstraints($this->extent);
        $this->extent = [
            $this->native->widthAnchor()->constraintGreaterThanOrEqualToConstant((float) $right),
            $this->native->heightAnchor()->constraintGreaterThanOrEqualToConstant((float) $bottom),
        ];
        NSLayoutConstraint::activateConstraints($this->extent);
    }
}
