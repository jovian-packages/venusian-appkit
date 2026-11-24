<?php

namespace Jovian\Toolkits\Appkit\UI;

use NSRect;
use NSEventType;
use NSApplication;
use NSObject;
use NSSplitView;
use WeakReference;
use NSLayoutConstraint;
use NSNotificationCenter;
use NSSplitViewDividerStyle;
use Surface\Toolkits\UI\TKPrimitive;
use Surface\Toolkits\UI\TKSplitView;
use Jovian\Toolkits\Appkit\UI\Concerns\AppkitNative;
use Jovian\Toolkits\Appkit\UI\Concerns\StretchesChildren;
use Jovian\Toolkits\Appkit\UI\Concerns\CarriesEnabledDown;

/**
 * An NSSplitView. Under Auto Layout setPositionOfDividerAtIndex() lasts until the next layout
 * (observed), so the divider is a constraint on the first pane's length, just under the
 * priority of AppKit's own drag (490): a drag wins, and the constraint follows it.
 *
 * A new length puts the divider back at its fraction.
 *
 * A drag runs in AppKit's own tracking loop inside sendEvent: the pump does not return until
 * the mouse is released, and event monitors see none of it (NSEvent.h). The resize
 * notification is called all through it: the same length while the event being tracked is a
 * drag is the user's move. ext-appkit binds no NSSplitView::setDelegate, so
 * constrainSplitPosition cannot be had.
 */
class AppkitSplitView extends TKSplitView
{
    use AppkitNative {
        releaseNative as private releaseView;
    }
    use CarriesEnabledDown;
    use StretchesChildren;

    private const float PRIORITY = 489.0;

    private ?NSObject $resizes = null;

    private float $length = 0.0;

    private ?NSLayoutConstraint $divider = null;

    /** The pane $divider is on, by its uuid. */
    private ?string $divided = null;

    protected function bootstrapNative(): void
    {
        $split = NSSplitView::initWithFrame(new NSRect(0.0, 0.0, 0.0, 0.0));
        $split->setVertical($this->isHorizontal());
        $split->setDividerStyle(NSSplitViewDividerStyle::THIN);
        $this->adoptNative($split);

        $self = WeakReference::create($this);
        $this->resizes = NSNotificationCenter::defaultCenter()->addObserverForNameObjectQueueUsingBlock(
            NSSplitViewDidResizeSubviewsNotification,
            $split,
            null,
            static function () use ($self): void {
                $self->get()?->resized();
            },
        );
    }

    protected function attachNative(TKPrimitive $child): void
    {
        self::stretch($child->native());
        $this->splitNative()->addArrangedSubview($child->native());
        $this->tellExpansion();
    }

    protected function detachNative(TKPrimitive $child): void
    {
        $this->dropDivider();
        $this->splitNative()->removeArrangedSubview($child->native());
        $child->native()->removeFromSuperview();
        $this->tellExpansion();
    }

    protected function reorderNative(TKPrimitive $child, int $index): void
    {
        $this->dropDivider();
        $this->splitNative()->removeArrangedSubview($child->native());
        $this->splitNative()->insertArrangedSubviewAtIndex($child->native(), $index);
    }

    protected function applyPosition(float $position): void
    {
        $room = $this->room();
        $first = $this->children()[0] ?? null;
        if ($room <= 0.0 || is_null($first)) {
            return;
        }

        if ($this->divided !== $first->uuid()) {
            $this->dropDivider();
            $view = $first->native();
            $this->divider = ($this->isHorizontal() ? $view->widthAnchor() : $view->heightAnchor())->constraintEqualToConstant($position * $room);
            $this->divider->setPriority(self::PRIORITY);
            $this->divider->setActive(true);
            $this->divided = $first->uuid();

            return;
        }
        $this->divider->setConstant($position * $room);
    }

    protected function childVisibilityChanged(TKPrimitive $child): void
    {
        if (! $this->hasDivider()) {
            $this->dropDivider();
        }
        parent::childVisibilityChanged($child);
        $this->tellExpansion();
    }

    private function dropDivider(): void
    {
        $this->divider?->setActive(false);
        $this->divider = null;
        $this->divided = null;
    }

    protected function childLayoutChanged(TKPrimitive $child): void
    {
        $this->tellExpansion();
    }

    protected function releaseNative(): void
    {
        if (! is_null($this->resizes)) {
            NSNotificationCenter::defaultCenter()->removeObserver($this->resizes);
            $this->resizes = null;
        }
        $this->dropDivider();
        $this->releaseView();
    }

    private function resized(): void
    {
        if ($this->isRemoved() || $this->isWriting() || ! $this->hasDivider()) {
            return;
        }

        $room = $this->room();
        if ($room <= 0.0) {
            return;
        }
        if (abs($room - $this->length) >= 0.5) {
            $this->length = $room;
            $this->write(fn () => $this->applyPosition($this->position()));

            return;
        }
        if (NSApplication::sharedApplication()->currentEvent()?->type() === NSEventType::LEFT_MOUSE_DRAGGED) {
            $first = $this->children()[0]->native()->frame();
            $this->dragged(($this->isHorizontal() ? $first->width : $first->height) / $room);
            $this->write(fn () => $this->applyPosition($this->position()));
        }
    }

    /** The length there is for the panes. */
    private function room(): float
    {
        $split = $this->splitNative();
        $bounds = $split->bounds();

        return ($this->isHorizontal() ? $bounds->width : $bounds->height) - $split->dividerThickness();
    }

    private function splitNative(): NSSplitView
    {
        /** @var NSSplitView */
        return $this->native();
    }
}
