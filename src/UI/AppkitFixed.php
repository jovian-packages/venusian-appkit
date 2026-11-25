<?php

namespace Jovian\Toolkits\Appkit\UI;

use NSRect;
use NSView;
use NSObject;
use WeakReference;
use NSLayoutConstraint;
use NSNotificationCenter;
use Surface\Toolkits\ToolkitException;
use Surface\Toolkits\UI\TKFixed;
use Surface\Toolkits\UI\TKPrimitive;
use Surface\Contracts\Toolkits\UI\Placement;
use Surface\Contracts\Toolkits\UI\TKPrimitive as ChildContract;
use Jovian\Toolkits\Appkit\UI\Concerns\AppkitNative;
use Jovian\Toolkits\Appkit\UI\Concerns\CarriesEnabledDown;

/**
 * A plain NSView whose children sit at frames, as GTK and Qt allocate them: each child's
 * frame on screen is its Placement (a control's bezel included), set with setFrame, not with
 * constraints. Frames are top-left based; AppKit's origin is the bottom-left and a plain
 * NSView cannot be made flipped, so each child's y is flipped against the fixed's height, and
 * every child is framed again when the fixed's own frame changes.
 *
 * A child is at least its minimum size. The fixed's own minimum size covers every frame,
 * since its children do not size it.
 *
 * A child's margin moves its frame in from the Placement's corner (left and top), and the
 * fixed's floor reaches past it by the right and bottom margin.
 */
class AppkitFixed extends TKFixed
{
    use AppkitNative {
        releaseNative as private releaseView;
    }
    use CarriesEnabledDown;

    /**
     * The width/height floors at the furthest frame, or at minSize() when larger.
     * @var list<NSLayoutConstraint>
     */
    private array $extent = [];

    /**
     * Token of the fixed's frame-change observer.
     */
    private ?NSObject $frame_observer = null;

    protected function bootstrapNative(): void
    {
        $view = NSView::initWithFrame(new NSRect(0.0, 0.0, 0.0, 0.0));
        $this->adoptNative($view);

        $view->setPostsFrameChangedNotifications(true);
        // The block runs on the posting thread (no queue): the main thread, inside the layout
        // pass that moved the fixed. It holds the primitive weakly.
        $self = WeakReference::create($this);
        $this->frame_observer = NSNotificationCenter::defaultCenter()->addObserverForNameObjectQueueUsingBlock(
            NSViewFrameDidChangeNotification,
            $view,
            null,
            static function () use ($self): void {
                $self->get()?->reframeAll();
            },
        );
    }

    /**
     * At the frame it was created at ($child->placement()), by its frame: the view keeps
     * translating its autoresizing mask into constraints.
     */
    protected function attachNative(TKPrimitive $child): void
    {
        $view = $child->native();
        $view->setTranslatesAutoresizingMaskIntoConstraints(true);
        $this->native()->addSubview($view);
        $this->frameChild($child, $child->placement());
        $this->settleExtent();
    }

    protected function detachNative(TKPrimitive $child): void
    {
        $child->native()->removeFromSuperview();
        $this->settleExtent();
    }

    protected function applyFrame(ChildContract $child, Placement $frame): void
    {
        $this->frameChild($child, $frame);
        $this->settleExtent();
    }

    /**
     * A child's minimum size or margin changed: it is framed again at the larger of frame and
     * minimum, in from its margin.
     */
    protected function childLayoutChanged(TKPrimitive $child): void
    {
        $this->frameChild($child, $this->frameOf($child));
        $this->settleExtent();
    }

    /**
     * The fixed's own floor joins the extent. (Inside another fixed, that one framed it first.)
     */
    protected function applyMinSize(int $width, int $height): void
    {
        $this->settleExtent();
    }

    /**
     * Never reached: only a column or row lets a child move (TKPrimitive::moveTo refuses here).
     *
     * @throws ToolkitException
     */
    protected function reorderNative(TKPrimitive $child, int $index): void
    {
        throw new ToolkitException("'{$this->path()}' keeps no order: its children are placed by frame.");
    }

    protected function releaseNative(): void
    {
        if (! is_null($this->frame_observer)) {
            NSNotificationCenter::defaultCenter()->removeObserver($this->frame_observer);
            $this->frame_observer = null;
        }
        NSLayoutConstraint::deactivateConstraints($this->extent);
        $this->extent = [];
        $this->releaseView();
    }

    /**
     * The fixed's frame changed (its height moves every top-left based y): each child again.
     */
    private function reframeAll(): void
    {
        if (is_null($this->native)) {
            return;
        }
        foreach ($this->children() as $child) {
            $this->frameChild($child, $this->frameOf($child));
        }
    }

    /**
     * setFrame with y flipped against the fixed's height.
     */
    private function frameChild(TKPrimitive $child, Placement $frame): void
    {
        [$width, $height] = $this->sizeOf($child, $frame);
        $top = $this->native()->frame()->height;
        $margin = $child->margin();
        [$x, $y] = [$frame->x + $margin->left, $frame->y + $margin->top];

        $child->native()->setFrame(new NSRect((float) $x, $top - $y - $height, $width, $height));
    }

    /**
     * The frame's size, or the child's minimum size where that is larger.
     *
     * @return array{float, float}
     */
    private function sizeOf(TKPrimitive $child, Placement $frame): array
    {
        [$width, $height] = $child->minimumSize();

        return [(float) max($width, $frame->width), (float) max($height, $frame->height)];
    }

    /**
     * Floors at the right and bottom edge of the furthest child (its margin included), and at
     * minSize().
     */
    private function settleExtent(): void
    {
        [$width, $height] = $this->minimumSize();
        foreach ($this->children() as $child) {
            $frame = $this->frameOf($child);
            [$w, $h] = $this->sizeOf($child, $frame);
            $margin = $child->margin();
            $width = max($width, $frame->x + $margin->left + $w + $margin->right);
            $height = max($height, $frame->y + $margin->top + $h + $margin->bottom);
        }

        NSLayoutConstraint::deactivateConstraints($this->extent);
        $fixed = $this->native();
        $this->extent = [
            $fixed->widthAnchor()->constraintGreaterThanOrEqualToConstant((float) $width),
            $fixed->heightAnchor()->constraintGreaterThanOrEqualToConstant((float) $height),
        ];
        NSLayoutConstraint::activateConstraints($this->extent);
    }
}
