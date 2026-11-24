<?php

namespace Jovian\Toolkits\Appkit\UI\Concerns;

use NSView;
use NSPoint;
use NSObject;
use NSClipView;
use NSScrollView;
use WeakReference;
use NSNotificationCenter;
use Surface\Contracts\Toolkits\Styling\ScrollPosition;

/**
 * The scrolling of a list and a collection: the clip view's bounds are where it is.
 */
trait AppkitItemScroll
{
    private ?NSClipView $clip = null;

    private ?NSView $document = null;

    protected ?NSObject $scroll_observer = null;

    protected function observeScroll(NSScrollView $scroll, NSView $document): void
    {
        $this->document = $document;
        $this->clip = $scroll->contentView();
        $this->clip->setPostsBoundsChangedNotifications(true);
        $this->scroll_observer = NSNotificationCenter::defaultCenter()->addObserverForNameObjectQueueUsingBlock(
            NSViewBoundsDidChangeNotification,
            $this->clip,
            null,
            (static fn (WeakReference $self) => static function () use ($self): void {
                $self->get()?->scrolled();
            })(WeakReference::create($this)),
        );
    }

    protected function stopObservingScroll(): void
    {
        if (! is_null($this->scroll_observer)) {
            NSNotificationCenter::defaultCenter()->removeObserver($this->scroll_observer);
            $this->scroll_observer = null;
        }
    }

    protected function nativeScrollPosition(): ScrollPosition
    {
        $visible = $this->clip->bounds();
        $content = $this->document->frame();

        return ScrollPosition::of($visible->x, $visible->width, $content->width, $visible->y, $visible->height, $content->height);
    }

    protected function applyScrollTo(float $x, float $y): void
    {
        $visible = $this->clip->bounds();
        $content = $this->document->frame();
        $this->clip->scrollToPoint(new NSPoint(
            ScrollPosition::offset($x, $visible->width, $content->width),
            ScrollPosition::offset($y, $visible->height, $content->height),
        ));
        $this->native()->reflectScrolledClipView($this->clip);
    }
}
