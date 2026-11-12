<?php

namespace Jovian\Toolkits\Appkit\Primitives;

use Jovian\Toolkits\Appkit\Primitives\Concerns\AppkitPrimitive;
use NSLayoutConstraint;
use NSNotificationCenter;
use NSOperationQueue;
use NSPoint;
use NSRect;
use NSScrollView;
use NSTimer;
use ObjCTarget;
use Surface\Contracts\Windows\Primitives\Placement;
use Surface\Contracts\Windows\Primitives\TKPrimitive as Child;
use Surface\Contracts\Windows\ToolkitWindow;
use Surface\Windows\Primitives\TKPrimitiveGroup;
use Surface\Windows\Primitives\TKScrollView;
use WeakReference;

/**
 * An NSScrollView whose document is its one container, pinned to the clip view's top and
 * leading edges: the top stays in view and stays put as the content grows. On an axis that
 * scrolls the document is at least the viewport; on one that does not it is exactly the
 * viewport, so its content wraps to it. A hidden scroll view's clip is 0 tall, and AppKit shows
 * it again scrolled to the document's bottom: shown again, it scrolls to the top.
 */
class AppkitScrollView extends TKScrollView
{
    use AppkitPrimitive;

    /**
     * @var list<NSLayoutConstraint>
     */
    protected array $document = [];

    /** The clip view's height when its frame last changed: 0 while hidden. */
    protected float $clip_height = 0.0;

    /** Watches the clip view's frame for the scroll view being shown again. */
    protected ?object $clip_observer = null;

    /** The scroll to the top asked for on being shown again, while it waits for the next run-loop turn. */
    protected ?array $to_top = null;

    public function __construct(string $name, ToolkitWindow $window, ?TKPrimitiveGroup $parent, Placement $placement)
    {
        parent::__construct($name, $window, $parent, $placement);
        $scroll = $this->adoptNative(NSScrollView::initWithFrame(new NSRect(0.0, 0.0, 0.0, 0.0)));
        $scroll->setHasHorizontalScroller($this->scroll_horizontal);
        $scroll->setHasVerticalScroller($this->scroll_vertical);
        $scroll->setDrawsBackground(false);
        $clip = $scroll->contentView();
        $clip->setPostsFrameChangedNotifications(true);
        $this->clip_observer = NSNotificationCenter::defaultCenter()->addObserverForNameObjectQueueUsingBlock(
            NSViewFrameDidChangeNotification,
            $clip,
            NSOperationQueue::mainQueue(),
            fn () => $this->clipResized(),
        );
    }

    /** From 0 tall (hidden) to a height: show the document's top, as on first show. */
    protected function clipResized(): void
    {
        $clip = $this->native->contentView();
        $height = $clip->frame()->height;
        $was = $this->clip_height;
        $this->clip_height = $height;
        if ($was > 0.0 || $height <= 0.0 || $clip->isFlipped() || ! is_null($this->to_top)) {
            return;
        }
        // The frame changes inside a layout pass, before the document has its final frame: the
        // scroll runs on the next run-loop turn.
        $self = WeakReference::create($this);
        $target = new ObjCTarget(static function () use ($self): void {
            $view = $self->get();
            if (! is_null($view)) {
                $view->to_top = null;
                $view->removed || $view->scrollToTop();
            }
        });
        $this->to_top = [NSTimer::scheduledTimerWithTimeIntervalTargetSelectorUserInfoRepeats(0.0, $target, ObjCTarget::ACTION, null, false), $target];
    }

    protected function scrollToTop(): void
    {
        $clip = $this->native->contentView();
        $document = $this->native->documentView();
        if (is_null($document)) {
            return;
        }
        $frame = $document->frame();
        $clip->scrollToPoint(new NSPoint($clip->bounds()->x, $frame->y + $frame->height - $clip->bounds()->height));
        $this->native->reflectScrolledClipView($clip);
    }

    protected function releaseNative(): void
    {
        is_null($this->to_top) || $this->to_top[0]->invalidate();
        $this->to_top = null;
        if (! is_null($this->clip_observer)) {
            NSNotificationCenter::defaultCenter()->removeObserver($this->clip_observer);
            $this->clip_observer = null;
        }
    }

    public function native(): NSScrollView
    {
        return $this->native;
    }

    protected function insertNative(Child $child): void
    {
        $this->native->setDocumentView(self::nativeOf($child));
        $this->placeChild($child);
        $child->syncNativeEnabled();
        $this->replaceInAncestors();
    }

    protected function applyScrollbars(bool $horizontal, bool $vertical): void
    {
        $this->native->setHasHorizontalScroller($horizontal);
        $this->native->setHasVerticalScroller($vertical);

        $content = $this->content();
        if (! is_null($content)) {
            $this->placeChild($content);
        }
    }

    /**
     * Pin the document to the clip view per the scrolling axes.
     *
     * @param Child $child
     * @return void
     */
    public function placeChild(Child $child): void
    {
        NSLayoutConstraint::deactivateConstraints($this->document);

        $view = self::nativeOf($child);
        $clip = $this->native->contentView();
        $this->document = [
            $view->leadingAnchor()->constraintEqualToAnchor($clip->leadingAnchor()),
            $view->topAnchor()->constraintEqualToAnchor($clip->topAnchor()),
            $this->scroll_horizontal
                ? $view->widthAnchor()->constraintGreaterThanOrEqualToAnchor($clip->widthAnchor())
                : $view->widthAnchor()->constraintEqualToAnchor($clip->widthAnchor()),
            $this->scroll_vertical
                ? $view->heightAnchor()->constraintGreaterThanOrEqualToAnchor($clip->heightAnchor())
                : $view->heightAnchor()->constraintEqualToAnchor($clip->heightAnchor()),
        ];
        NSLayoutConstraint::activateConstraints($this->document);
    }

    /**
     * @param Child $child
     * @return void
     */
    public function removeNative(Child $child): void
    {
        NSLayoutConstraint::deactivateConstraints($this->document);
        $this->document = [];
        $this->native->setDocumentView(null);
        $this->replaceInAncestors();
    }
}
