<?php

namespace Jovian\Toolkits\Appkit\UI\Support;

use NSRect;
use NSView;
use NSEvent;
use NSCursor;
use NSObject;
use NSEventMask;
use NSEventType;
use NSTrackingArea;
use NSTrackingAreaOptions;

/**
 * The pointer shape of the views given one, through AppKit's own cursor-update tracking. Each
 * view gets an NSTrackingArea over its visible rect with cursor updates on, owned by the view;
 * one local monitor (shared by every view) sees its events. AppKit sends the area a cursor
 * update when the pointer comes in and again when it leaves: coming in (the pointer inside the
 * view), the monitor sets the view's NSCursor and takes the event, so the owner's own
 * cursorUpdate: does not put the arrow back; leaving, the event goes on to AppKit, which shows
 * its own pointer again. The enter sets the cursor too, in case it comes first.
 *
 * AppKit fires a tracking area per view, whatever covers it, so a view's cursor is set only
 * when the view is the one under the pointer, or holds it: the window's content view
 * hit-tests the point (hidden views take no hit), and the hit view's superviews are walked up
 * to the tracked view. Under a covering view (an open drawer) the page's cursor stays unshown.
 */
final class AppkitPointer
{
    private const int OPTIONS = NSTrackingAreaOptions::CURSOR_UPDATE->value
        | NSTrackingAreaOptions::MOUSE_ENTERED_AND_EXITED->value
        | NSTrackingAreaOptions::ACTIVE_IN_ACTIVE_APP->value
        | NSTrackingAreaOptions::IN_VISIBLE_RECT->value;

    private const int EVENTS = NSEventMask::CURSOR_UPDATE->value | NSEventMask::MOUSE_ENTERED->value;

    /** Installed while any view has a cursor. */
    private static ?NSObject $monitor = null;

    /** @var list<array{NSTrackingArea, NSView, NSCursor}> */
    private static array $areas = [];

    /**
     * Track $view with $cursor; the area to hand back to untrack().
     */
    public static function track(NSView $view, NSCursor $cursor): NSTrackingArea
    {
        $area = NSTrackingArea::initWithRectOptionsOwnerUserInfo(new NSRect(0.0, 0.0, 0.0, 0.0), self::OPTIONS, $view, null);
        $view->addTrackingArea($area);
        self::$areas[] = [$area, $view, $cursor];

        self::$monitor ??= NSEvent::addLocalMonitorForEventsMatchingMaskHandler(
            self::EVENTS,
            static fn (NSEvent $event): ?NSEvent => self::seen($event),
        );

        return $area;
    }

    public static function untrack(NSView $view, NSTrackingArea $area): void
    {
        $view->removeTrackingArea($area);
        $at = self::indexOf($area);
        if (! is_null($at)) {
            array_splice(self::$areas, $at, 1);
        }

        if (self::$areas === [] && ! is_null(self::$monitor)) {
            NSEvent::removeMonitor(self::$monitor);
            self::$monitor = null;
        }
    }

    /**
     * The NSCursor a view's area sets, or null for an area this class did not make.
     */
    public static function cursorOf(NSTrackingArea $area): ?NSCursor
    {
        $at = self::indexOf($area);

        return is_null($at) ? null : self::$areas[$at][2];
    }

    /**
     * Events of other areas, cursor updates from cursor rects (a text field's I-beam, no area)
     * and the update of a pointer leaving go on to AppKit untouched.
     */
    private static function seen(NSEvent $event): ?NSEvent
    {
        $area = $event->trackingArea();
        $at = is_null($area) ? null : self::indexOf($area);
        if (is_null($at)) {
            return $event;
        }

        [, $view, $cursor] = self::$areas[$at];
        if (! self::inside($view, $event)) {
            return $event;
        }

        $cursor->set();

        return $event->type() === NSEventType::CURSOR_UPDATE ? null : $event;
    }

    private static function inside(NSView $view, NSEvent $event): bool
    {
        $point = $view->convertPointFromView($event->locationInWindow(), null);
        $bounds = $view->bounds();

        $within = $point->x >= $bounds->x && $point->x < $bounds->x + $bounds->width
            && $point->y >= $bounds->y && $point->y < $bounds->y + $bounds->height;

        return $within && self::uppermost($view, $event);
    }

    /**
     * Whether $view is, or holds, the view the window hit-tests at the event's point.
     */
    private static function uppermost(NSView $view, NSEvent $event): bool
    {
        $content = $view->window()?->contentView();
        $frame = $content?->superview();
        if (is_null($content) || is_null($frame)) {
            return true;
        }

        for ($hit = $content->hitTest($frame->convertPointFromView($event->locationInWindow(), null)); ! is_null($hit); $hit = $hit->superview()) {
            if ($hit === $view) {
                return true;
            }
        }

        return false;
    }

    private static function indexOf(NSTrackingArea $area): ?int
    {
        foreach (self::$areas as $at => [$tracked]) {
            if ($tracked === $area) {
                return $at;
            }
        }

        return null;
    }
}
