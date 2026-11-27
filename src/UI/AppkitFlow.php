<?php

namespace Jovian\Toolkits\Appkit\UI;

use NSRect;
use NSView;
use NSTimer;
use ObjCTarget;
use NSObject;
use WeakReference;
use NSLayoutConstraint;
use NSNotificationCenter;
use Surface\Toolkits\UI\TKFlow;
use Surface\Toolkits\UI\TKPrimitive;
use Surface\Contracts\Toolkits\UI\Insets;
use Jovian\Toolkits\Appkit\UI\Concerns\AppkitNative;
use Jovian\Toolkits\Appkit\UI\Concerns\CarriesEnabledDown;

/**
 * A plain NSView whose children sit side by side at their own size, wrapping onto the next
 * line where its width runs out. AppKit has no wrapping container (NSStackView does not wrap),
 * so the flow places them: each child's leading and top edges are constraints whose constants
 * are its place, worked out from the child's fitting size, its margin, the spacing and the
 * padding. They are worked out again when the flow's own frame changes (its width decides the
 * lines), when a child's does (its content changed), and when a child comes, goes, moves,
 * is shown or hidden. A hidden child takes no room.
 *
 * The flow's height follows its lines: a floor at the lines' height, so a column holding it
 * grows as it wraps. Its width is at least the widest child's box, so none is cut, and at
 * priority 485 (as a picture's natural size) one line of everything, which a column's pull to
 * zero (480) does not win and a window's size-stay-put (500) does.
 */
class AppkitFlow extends TKFlow
{
    use AppkitNative {
        releaseNative as private releaseView;
    }
    use CarriesEnabledDown;

    /**
     * Each child's leading and top constraints, by child uuid.
     * @var array<string, array{NSLayoutConstraint, NSLayoutConstraint}>
     */
    private array $places = [];

    /**
     * Each child's frame-change observer token, by child uuid.
     * @var array<string, NSObject>
     */
    private array $child_observers = [];

    /** Hears the flow's frame change: its width decides the lines. */
    private ?NSObject $frame_observer = null;

    /** The width floor: the widest child's box and the padding. */
    private ?NSLayoutConstraint $widest = null;

    /** The width of one line of everything, as a pull. */
    private ?NSLayoutConstraint $one_line = null;

    /** The height floor: the lines' height and the padding. */
    private ?NSLayoutConstraint $lines = null;

    /** The one-shot timer that lines the children up after the layout pass that asked, and its target. */
    private ?array $soon = null;

    protected function bootstrapNative(): void
    {
        $view = NSView::initWithFrame(new NSRect(0.0, 0.0, 0.0, 0.0));
        $this->adoptNative($view);

        $this->widest = $view->widthAnchor()->constraintGreaterThanOrEqualToConstant(0.0);
        $this->one_line = $view->widthAnchor()->constraintEqualToConstant(0.0);
        $this->one_line->setPriority(485.0);
        $this->lines = $view->heightAnchor()->constraintGreaterThanOrEqualToConstant(0.0);
        NSLayoutConstraint::activateConstraints([$this->widest, $this->one_line, $this->lines]);

        $view->setPostsFrameChangedNotifications(true);
        $this->frame_observer = self::observe($view, $this);
    }

    protected function attachNative(TKPrimitive $child): void
    {
        $view = $child->native();
        $this->native()->addSubview($view);
        $this->places[$child->uuid()] = [
            $view->leadingAnchor()->constraintEqualToAnchorConstant($this->native()->leadingAnchor(), 0.0),
            $view->topAnchor()->constraintEqualToAnchorConstant($this->native()->topAnchor(), 0.0),
        ];
        NSLayoutConstraint::activateConstraints($this->places[$child->uuid()]);

        $view->setPostsFrameChangedNotifications(true);
        $this->child_observers[$child->uuid()] = self::observe($view, $this);
        $this->lineUp();
    }

    protected function detachNative(TKPrimitive $child): void
    {
        NSLayoutConstraint::deactivateConstraints($this->places[$child->uuid()] ?? []);
        unset($this->places[$child->uuid()]);
        if (isset($this->child_observers[$child->uuid()])) {
            NSNotificationCenter::defaultCenter()->removeObserver($this->child_observers[$child->uuid()]);
            unset($this->child_observers[$child->uuid()]);
        }

        $child->native()->removeFromSuperview();
        $this->lineUp();
    }

    /**
     * The children are already in their new order: the places follow it.
     */
    protected function reorderNative(TKPrimitive $child, int $index): void
    {
        $this->lineUp();
    }

    protected function applySpacing(int $spacing): void
    {
        $this->lineUp();
    }

    protected function applyPadding(Insets $padding): void
    {
        $this->lineUp();
    }

    /**
     * A child's margin or minimum size changed: its box is another size.
     */
    protected function childLayoutChanged(TKPrimitive $child): void
    {
        $this->lineUp();
    }

    /**
     * A shown child has a place; a hidden one gives its room to the rest.
     */
    protected function childVisibilityChanged(TKPrimitive $child): void
    {
        $this->lineUp();
    }

    protected function releaseNative(): void
    {
        $center = NSNotificationCenter::defaultCenter();
        foreach ([$this->frame_observer, ...array_values($this->child_observers)] as $observer) {
            if (! is_null($observer)) {
                $center->removeObserver($observer);
            }
        }
        [$this->frame_observer, $this->child_observers] = [null, []];
        if (! is_null($this->soon)) {
            $this->soon[0]->invalidate();
            $this->soon = null;
        }

        foreach ($this->places as $places) {
            NSLayoutConstraint::deactivateConstraints($places);
        }
        NSLayoutConstraint::deactivateConstraints(array_filter([$this->widest, $this->one_line, $this->lines]));
        [$this->places, $this->widest, $this->one_line, $this->lines] = [[], null, null, null];
        $this->releaseView();
    }

    /**
     * The block runs on the posting thread (no queue): the main thread, inside the layout pass
     * that moved the view. It holds the flow weakly.
     */
    private static function observe(NSView $view, self $flow): NSObject
    {
        return NSNotificationCenter::defaultCenter()->addObserverForNameObjectQueueUsingBlock(
            NSViewFrameDidChangeNotification,
            $view,
            null,
            (static fn (WeakReference $self) => static function () use ($self): void {
                $self->get()?->lineUpSoon();
            })(WeakReference::create($flow)),
        );
    }

    /**
     * A frame changed inside a layout pass: constants changed there are not laid out again,
     * so the lining up waits for the next run-loop turn (measured: a child's leading edge
     * kept its old constant while its top took the new one).
     */
    private function lineUpSoon(): void
    {
        if (! is_null($this->soon)) {
            return;
        }
        $self = WeakReference::create($this);
        $target = new ObjCTarget(static function () use ($self): void {
            $flow = $self->get();
            if (! is_null($flow)) {
                $flow->soon = null;
                $flow->lineUp();
            }
        });
        $this->soon = [NSTimer::scheduledTimerWithTimeIntervalTargetSelectorUserInfoRepeats(0.0, $target, ObjCTarget::ACTION, null, false), $target];
    }

    /**
     * Each shown child at the next place on the line, or at the start of the next line where it
     * would pass the flow's right padding (a child wider than the room has a line to itself).
     * A flow with no width yet keeps one line. Then the floors.
     */
    private function lineUp(): void
    {
        if (is_null($this->native) || is_null($this->lines)) {
            return;
        }

        $padding = $this->padding();
        $spacing = (float) $this->spacing();
        $width = $this->native->frame()->width;
        $room = $width > 0.0 ? $width - $padding->right : INF;
        $edge = (float) $padding->left;

        [$x, $y, $line, $widest, $across] = [$edge, (float) $padding->top, 0.0, 0.0, 0.0];
        foreach ($this->children() as $child) {
            if (! isset($this->places[$child->uuid()])) {
                continue;
            }
            if (! $child->isVisible()) {
                $this->place($child, 0.0, 0.0);

                continue;
            }

            $margin = $child->margin();
            $fit = $child->native()->fittingSize();
            $box = $margin->left + $fit->width + $margin->right;
            $height = $margin->top + $fit->height + $margin->bottom;

            if ($x > $edge && $x + $box > $room) {
                [$x, $y, $line] = [$edge, $y + $line + $spacing, 0.0];
            }
            $this->place($child, $x + $margin->left, $y + $margin->top);

            $x += $box + $spacing;
            $line = max($line, $height);
            $widest = max($widest, $box);
            $across += $box + $spacing;
        }

        $this->widest->setConstant($padding->left + $widest + $padding->right);
        $this->one_line->setConstant($padding->left + max(0.0, $across - $spacing) + $padding->right);
        $this->lines->setConstant($y + $line + $padding->bottom);
    }

    private function place(TKPrimitive $child, float $x, float $y): void
    {
        [$leading, $top] = $this->places[$child->uuid()];
        if ($leading->constant() !== $x) {
            $leading->setConstant($x);
        }
        if ($top->constant() !== $y) {
            $top->setConstant($y);
        }
    }
}
