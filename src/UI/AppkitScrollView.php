<?php

namespace Jovian\Toolkits\Appkit\UI;

use NSRect;
use NSObject;
use WeakReference;
use NSNotificationCenter;
use Surface\Contracts\Toolkits\Styling\ScrollPosition;
use NSScrollView;
use NSLayoutConstraint;
use Surface\Toolkits\UI\TKPrimitive;
use Surface\Toolkits\UI\TKScrollView;
use Jovian\Toolkits\Appkit\UI\Concerns\AppkitNative;
use Jovian\Toolkits\Appkit\UI\Concerns\StretchesChildren;
use Jovian\Toolkits\Appkit\UI\Concerns\CarriesEnabledDown;

/**
 * An NSScrollView. The document is pinned to the clip view's top, at least as tall as it
 * when it scrolls down, and as wide as it unless it scrolls across.
 *
 * The document's margin is not shown. The scroll view's content insets are not it (measured
 * 2026-11-24 with a margin of 20 50 40 30): they inset the scroller too (16x300 at the edge
 * became 16x240, 30 in), the pinned document stayed the clip view's width and ran 50 past the
 * right edge, and its top sat 40 above the view instead of 20 below.
 */
class AppkitScrollView extends TKScrollView
{
    use AppkitNative {
        releaseNative as private releaseView;
    }
    use CarriesEnabledDown;
    use StretchesChildren;

    /** @var list<NSLayoutConstraint> */
    private array $document = [];

    /** Hears the clip view's bounds change: a scroll. */
    private ?NSObject $scroll_observer = null;

    protected function bootstrapNative(): void
    {
        $scroll = NSScrollView::initWithFrame(new NSRect(0.0, 0.0, 0.0, 0.0));
        $scroll->setDrawsBackground(false);
        $this->adoptNative($scroll);

        // A scroll moves the clip view's bounds; AppKit posts that only when asked.
        $clip = $scroll->contentView();
        $clip->setPostsBoundsChangedNotifications(true);
        $this->scroll_observer = NSNotificationCenter::defaultCenter()->addObserverForNameObjectQueueUsingBlock(
            NSViewBoundsDidChangeNotification,
            $clip,
            null,
            (static fn (WeakReference $self) => static function () use ($self): void {
                $self->get()?->scrolled();
            })(WeakReference::create($this)),
        );
        $this->applyScrollbars($this->scrollsAcross(), $this->scrollsDown());
    }

    protected function attachNative(TKPrimitive $child): void
    {
        $this->scrollNative()->setDocumentView($child->native());
        $this->pin($child);
        $this->tellExpansion();
    }

    protected function detachNative(TKPrimitive $child): void
    {
        NSLayoutConstraint::deactivateConstraints($this->document);
        $this->document = [];
        $this->scrollNative()->setDocumentView(null);
        $this->tellExpansion();
    }

    protected function applyScrollbars(bool $horizontal, bool $vertical): void
    {
        $this->scrollNative()->setHasHorizontalScroller($horizontal);
        $this->scrollNative()->setHasVerticalScroller($vertical);

        $content = $this->content();
        if (! is_null($content)) {
            $this->pin($content);
        }
    }

    protected function childLayoutChanged(TKPrimitive $child): void
    {
        $this->tellExpansion();
    }

    protected function childVisibilityChanged(TKPrimitive $child): void
    {
        $this->tellExpansion();
    }

    /**
     * The clip view's bounds against the document's frame, both in the clip view's own
     * coordinates. The pinned document's frame does not start at zero there, so offsets are
     * taken from its edges; a clip view that is not flipped counts y from the bottom, and the
     * top of what shows is then the document's far edge less the bounds' far edge.
     */
    protected function nativeScrollPosition(): ScrollPosition
    {
        $document = $this->scrollNative()->documentView();
        if (is_null($document)) {
            return new ScrollPosition(0.0, 0.0, 1.0, 1.0);
        }
        $clip = $this->scrollNative()->contentView();
        $visible = $clip->bounds();
        $content = $document->frame();
        $top = $clip->isFlipped()
            ? $visible->y - $content->y
            : ($content->y + $content->height) - ($visible->y + $visible->height);

        return ScrollPosition::of($visible->x - $content->x, $visible->width, $content->width, $top, $visible->height, $content->height);
    }

    protected function releaseNative(): void
    {
        if (! is_null($this->scroll_observer)) {
            NSNotificationCenter::defaultCenter()->removeObserver($this->scroll_observer);
            $this->scroll_observer = null;
        }
        NSLayoutConstraint::deactivateConstraints($this->document);
        $this->document = [];
        $this->releaseView();
    }

    private function pin(TKPrimitive $child): void
    {
        NSLayoutConstraint::deactivateConstraints($this->document);

        $view = $child->native();
        $clip = $this->scrollNative()->contentView();
        $constraints = [
            $view->leadingAnchor()->constraintEqualToAnchor($clip->leadingAnchor()),
            $view->topAnchor()->constraintEqualToAnchor($clip->topAnchor()),
        ];
        foreach ([[$this->scrollsAcross(), $view->widthAnchor(), $clip->widthAnchor()], [$this->scrollsDown(), $view->heightAnchor(), $clip->heightAnchor()]] as [$scrolls, $length, $room]) {
            if (! $scrolls) {
                $constraints[] = $length->constraintEqualToAnchor($room);
                continue;
            }
            $constraints[] = $length->constraintGreaterThanOrEqualToAnchor($room);
            // No larger than the view unless what it holds is.
            $snug = $length->constraintEqualToAnchor($room);
            $snug->setPriority(100.0);
            $constraints[] = $snug;
        }

        NSLayoutConstraint::activateConstraints($constraints);
        $this->document = $constraints;
    }

    private function scrollNative(): NSScrollView
    {
        /** @var NSScrollView */
        return $this->native();
    }
}
