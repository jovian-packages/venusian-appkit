<?php

namespace Jovian\Toolkits\Appkit\Primitives\Concerns;

use NSClipView;
use NSNotificationCenter;
use NSObject;
use NSPoint;
use NSScrollView;
use NSView;
use Surface\Contracts\Toolkits\Mail\Views\ViewScrolled;
use Surface\Contracts\Toolkits\Styling\ScrollPosition;

/**
 * An item view's scrolling: the clip view's bounds changes, observed synchronously, post
 * ViewScrolled (latest-only) while watched; a scroll from code runs quiet.
 */
trait AppkitItemScroll
{
    protected NSClipView $clip;

    protected NSView $document;

    protected ?NSObject $scroll_observer = null;

    protected bool $quiet = false;

    protected function observeScroll(NSScrollView $scroll, NSView $document): void
    {
        $this->document = $document;
        $this->clip = $scroll->contentView();
        $this->clip->setPostsBoundsChangedNotifications(true);
        $this->scroll_observer = NSNotificationCenter::defaultCenter()->addObserverForNameObjectQueueUsingBlock(
            NSViewBoundsDidChangeNotification,
            $this->clip,
            null,
            fn () => $this->scrolled(),
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
        $this->quiet = true;
        try {
            $this->clip->scrollToPoint(new NSPoint(
                ScrollPosition::offset($x, $visible->width, $content->width),
                ScrollPosition::offset($y, $visible->height, $content->height),
            ));
            $this->native->reflectScrolledClipView($this->clip);
        } finally {
            $this->quiet = false;
        }
    }

    protected function scrolled(): void
    {
        if ($this->quiet || $this->removed || ! $this->watching_scroll) {
            return;
        }
        $this->session()->postLatest(
            "view.scrolled.{$this->window->name()}.{$this->path()}",
            new ViewScrolled($this->window->name(), $this->path(), $this->uuid, $this->nativeScrollPosition()),
        );
    }
}
