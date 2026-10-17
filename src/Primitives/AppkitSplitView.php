<?php

namespace Jovian\Toolkits\Appkit\Primitives;

use Jovian\Toolkits\Appkit\Primitives\Concerns\AppkitPrimitive;
use NSEvent;
use NSEventMask;
use NSNotificationCenter;
use NSObject;
use NSOperationQueue;
use NSRect;
use NSSplitView;
use NSSplitViewDividerStyle;
use NSView;
use Surface\Contracts\Windows\Mail\View\SplitMoved;
use Surface\Contracts\Windows\Primitives\Placement;
use Surface\Contracts\Windows\Primitives\TKPrimitive as Child;
use Surface\Contracts\Windows\ToolkitWindow;
use Surface\Windows\Primitives\TKPrimitiveGroup;
use Surface\Windows\Primitives\TKSplitView;

/**
 * An NSSplitView whose arranged subviews are the panes, in the order added; a hidden pane
 * collapses. A divider sits at the end of each visible pane but the last, so positions are
 * those ends over the split's length. Positions set before the split has a length wait for
 * its first layout of the panes, which otherwise shares the length equally.
 *
 * NSSplitView tracks a divider drag in a nested loop that takes the drag and the mouse-up
 * before any monitor sees them, so a local monitor takes the mouse-down on one of this
 * split's dividers and dispatches it itself: that dispatch returns when the drag ends, and
 * SplitMoved posts then if the positions moved. A window resize moves no divider by hand and
 * posts nothing.
 */
class AppkitSplitView extends TKSplitView
{
    use AppkitPrimitive;

    /**
     * Fractions set before the split had a length.
     * @var list<float>|null
     */
    protected ?array $pending_positions = null;

    protected ?NSObject $monitor = null;

    /** Hears the split lay its panes out: positions set before its first layout land after it. */
    protected ?NSObject $resize_observer_split = null;

    /** The split has had its first layout with a length. */
    protected bool $laid_out = false;

    /** The monitor is dispatching a divider mouse-down: the nested call passes through. */
    protected bool $dispatching = false;

    public function __construct(string $name, ToolkitWindow $window, ?TKPrimitiveGroup $parent, Placement $placement, bool $horizontal)
    {
        parent::__construct($name, $window, $parent, $placement, $horizontal);
        $split = $this->adoptNative(NSSplitView::initWithFrame(new NSRect(0.0, 0.0, 0.0, 0.0)));
        $split->setVertical($horizontal);
        $split->setDividerStyle(NSSplitViewDividerStyle::THIN);

        $this->resize_observer_split = NSNotificationCenter::defaultCenter()->addObserverForNameObjectQueueUsingBlock(
            NSSplitViewDidResizeSubviewsNotification,
            $split,
            NSOperationQueue::mainQueue(),
            fn () => $this->applyPending(),
        );
        $this->monitor = NSEvent::addLocalMonitorForEventsMatchingMaskHandler(
            NSEventMask::LEFT_MOUSE_DOWN,
            fn (NSEvent $event): ?NSEvent => $this->mouseDown($event),
        );
    }

    public function native(): NSSplitView
    {
        return $this->native;
    }

    protected function insertNative(Child $child): void
    {
        $this->native->addArrangedSubview(self::nativeOf($child));
        $this->placeChild($child);
        $child->syncNativeEnabled();
        $this->replaceInAncestors();
    }

    /**
     * The split sizes its panes: a pane grows on both axes, holding only its minSize().
     *
     * @param Child $child
     * @return void
     */
    public function placeChild(Child $child): void
    {
        $child->nativeHug(true, false);
        $child->nativeHug(false, false);
    }

    /**
     * @param Child $child
     * @return void
     */
    public function removeNative(Child $child): void
    {
        $view = self::nativeOf($child);
        $this->native->removeArrangedSubview($view);
        $view->removeFromSuperview();
        $this->replaceInAncestors();
    }

    protected function nativePositions(): array
    {
        $length = $this->length();
        $visible = $this->visibleViews();
        if ($length <= 0.0) {
            $count = count($visible) - 1;
            if ($count < 1) {
                return [];
            }

            return ! is_null($this->pending_positions) && count($this->pending_positions) === $count
                ? $this->pending_positions
                : array_map(fn (int $i): float => ($i + 1) / ($count + 1), range(0, $count - 1));
        }

        $positions = [];
        foreach (array_slice($visible, 0, -1) as $view) {
            $positions[] = $this->endOf($view) / $length;
        }

        return $positions;
    }

    protected function applyPositions(array $positions): void
    {
        $length = $this->length();
        // Until the first layout, it takes these positions instead of equal shares.
        $this->pending_positions = $this->laid_out ? null : $positions;
        if ($length <= 0.0) {
            return;
        }

        $arranged = $this->native->arrangedSubviews();
        $dividers = [];
        foreach (array_slice($this->visibleViews(), 0, -1) as $i => $view) {
            $dividers[] = [array_search($view, $arranged, true), $positions[$i] * $length];
        }
        // A divider cannot pass its neighbour: last to first lets dividers moving forward make
        // room, first to last lets those moving back; twice settles any mix.
        foreach ([1, 2] as $pass) {
            foreach ([array_reverse($dividers), $dividers] as $order) {
                foreach ($order as [$index, $position]) {
                    $this->native->setPositionOfDividerAtIndex($position, $index);
                }
            }
        }
    }

    protected function releaseNative(): void
    {
        if (! is_null($this->monitor)) {
            NSEvent::removeMonitor($this->monitor);
            $this->monitor = null;
        }
        if (! is_null($this->resize_observer_split)) {
            NSNotificationCenter::defaultCenter()->removeObserver($this->resize_observer_split);
            $this->resize_observer_split = null;
        }
    }

    /**
     * The split's first layout of its panes with a length takes the positions set before it,
     * or shares the length equally: AppKit's own first layout gives the leading panes only
     * their content's width.
     * @return void
     */
    protected function applyPending(): void
    {
        if ($this->removed || $this->laid_out || $this->length() <= 0.0) {
            return;
        }

        $this->laid_out = true;
        $dividers = $this->dividers();
        $pending = $this->pending_positions;
        $this->pending_positions = null;
        if ($dividers > 0) {
            $this->applyPositions(! is_null($pending) && count($pending) === $dividers
                ? $pending
                : array_map(fn (int $i): float => ($i + 1) / ($dividers + 1), range(0, $dividers - 1)));
        }
    }

    /**
     * A left mouse-down on one of this split's dividers: dispatch it here, so its drag runs
     * inside this call, then post SplitMoved if the drag moved a divider.
     *
     * @param NSEvent $event
     * @return NSEvent|null null when this handler dispatched the event itself
     */
    protected function mouseDown(NSEvent $event): ?NSEvent
    {
        if ($this->dispatching || $this->removed || $event->window() !== $this->native->window() || ! $this->onDivider($event)) {
            return $event;
        }

        $from = $this->nativePositions();
        $this->dispatching = true;
        try {
            $this->session()->application()->sendEvent($event);
        } finally {
            $this->dispatching = false;
        }

        $to = $this->nativePositions();
        $length = $this->length();
        $moved = count($to) !== count($from);
        foreach ($to as $i => $position) {
            $moved = $moved || abs($position - $from[$i]) * $length > 0.5;
        }
        if ($moved && ! $this->removed) {
            $this->session()->post(new SplitMoved($this->window->name(), $this->path(), $this->uuid, $to));
        }

        return null;
    }

    /**
     * Whether the event lands between two visible panes, the gap widened to 4 points.
     *
     * @param NSEvent $event
     * @return bool
     */
    protected function onDivider(NSEvent $event): bool
    {
        $point = $this->native->convertPointFromView($event->locationInWindow(), null);
        $bounds = $this->native->bounds();
        [$along, $across, $span] = $this->horizontal ? [$point->x, $point->y, $bounds->height] : [$this->flippedY($point->y), $point->x, $bounds->width];
        if ($across < 0.0 || $across > $span) {
            return false;
        }

        $visible = $this->visibleViews();
        for ($i = 0; $i < count($visible) - 1; $i++) {
            $start = $this->endOf($visible[$i]);
            $stop = $this->startOf($visible[$i + 1]);
            if ($stop - $start < 4.0) {
                $middle = ($start + $stop) / 2;
                [$start, $stop] = [$middle - 2.0, $middle + 2.0];
            }
            if ($along >= $start && $along <= $stop) {
                return true;
            }
        }

        return false;
    }

    /**
     * The visible panes' views, in order.
     * @return list<NSView>
     */
    protected function visibleViews(): array
    {
        $views = [];
        foreach ($this->children as $child) {
            if ($child->isVisible()) {
                $views[] = self::nativeOf($child);
            }
        }

        return $views;
    }

    protected function length(): float
    {
        $bounds = $this->native->bounds();

        return $this->horizontal ? $bounds->width : $bounds->height;
    }

    /**
     * Where a pane starts along the split, from its leading or top edge.
     *
     * @param NSView $view
     * @return float
     */
    protected function startOf(NSView $view): float
    {
        $frame = $view->frame();

        return $this->horizontal ? $frame->x : $this->flippedY($this->native->isFlipped() ? $frame->y : $frame->y + $frame->height);
    }

    /**
     * Where a pane ends along the split, from its leading or top edge.
     *
     * @param NSView $view
     * @return float
     */
    protected function endOf(NSView $view): float
    {
        $frame = $view->frame();

        return $this->horizontal ? $frame->x + $frame->width : $this->flippedY($this->native->isFlipped() ? $frame->y + $frame->height : $frame->y);
    }

    /**
     * A y in the split's coordinates as a distance from its top.
     *
     * @param float $y
     * @return float
     */
    protected function flippedY(float $y): float
    {
        return $this->native->isFlipped() ? $y : $this->length() - $y;
    }
}
