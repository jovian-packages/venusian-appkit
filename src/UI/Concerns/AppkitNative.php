<?php

namespace Jovian\Toolkits\Appkit\UI\Concerns;

use NSView;
use NSColor;
use NSTimer;
use NSCursor;
use NSTextView;
use ObjCTarget;
use NSTrackingArea;
use CAMediaTimingFunction;
use NSClickGestureRecognizer;
use WeakReference;
use RuntimeException;
use NSAnimationContext;
use NSLayoutConstraint;
use Surface\NutsAndBolts\Color;
use CALayer;
use CACornerMask;
use Surface\Contracts\Toolkits\UI\Align;
use Surface\Contracts\Toolkits\UI\Cursor;
use Surface\Contracts\Toolkits\UI\Corners;
use Surface\Contracts\Toolkits\UI\TKTransition;
use NSCursorFrameResizePosition;
use NSCursorFrameResizeDirections;
use Jovian\Toolkits\Appkit\UI\AppkitFixed;
use Jovian\Toolkits\Appkit\UI\AppkitOverlay;
use Jovian\Toolkits\Appkit\UI\Support\AppkitPointer;

/**
 * What every AppKit primitive does the same way with its view.
 *
 * Layout: views use Auto Layout. The container that holds a child owns the constraints that
 * place it (a stack's fill and alignment, a fixed's frame), so applyFill() and applyAlign()
 * have nothing to set on the view itself: the container did it in childLayoutChanged().
 *
 * Styling: background and corner radius are the view's layer (made layer-backed on first use).
 * The corners round the background; the view's clipsToBounds follows clip() (off unless
 * clipping, an NSStackView's own default included), so what is inside is cut only when asked.
 *
 * Border: the layer's borderWidth and borderColor; a null colour is NSColor separatorColor,
 * the system's line colour, resolved when the border is set. Tooltip: the view's
 * toolTip. Cursor: AppKit's cursor-update tracking (AppkitPointer). Margin: an AppKit view has
 * none of its own, so the container that places the child keeps it (childLayoutChanged), and
 * applyMargin() stays empty.
 *
 * Clicks on a group: AppKit's click gesture recognizer on the group's view (primary button,
 * one click), not delaying the press, so a control inside takes its own click and the
 * recognizer never sees its release (measured); passive content (a label, a picture) passes
 * the press up, and the group's recognizer takes the click.
 *
 * Transitions: AppKit plays the fade natively (an NSAnimationContext group with implicit
 * animation, run by Core Animation); a one-shot NSTimer lands it. The slide part of a
 * TKTransition is not played on AppKit: an NSStackView positions its arranged views itself,
 * and the next layout pass puts back a frame moved from PHP (observed), so only the fade runs.
 * A MOVE_* transition (the view's own full size from an edge, no fade) plays in an overlay,
 * which animates its own placement constraints for the child; elsewhere the container
 * positions the view, and it is shown or hidden at once.
 */
trait AppkitNative
{
    protected ?NSView $native = null;

    /**
     * The width/height floors minSize() set, outside a fixed.
     * @var list<NSLayoutConstraint>
     */
    private array $min_size_constraints = [];

    /**
     * The running transition's pending steps: each timer and the target it calls. AppKit
     * holds a timer's target strongly, but the timer is kept to invalidate it.
     * @var list<array{NSTimer, ObjCTarget}>
     */
    private array $transition_timers = [];

    /** The overlay holding this view's placement while a move plays. */
    private ?AppkitOverlay $moving_in = null;

    /** The click recognizer while the group is clickable, and its target (AppKit does not retain it). */
    private ?NSClickGestureRecognizer $click = null;

    private ?ObjCTarget $click_target = null;

    /** The cursor-update area while a cursor is shown over the view. */
    private ?NSTrackingArea $cursor_area = null;

    /**
     * The view, for the container this primitive is in.
     *
     * @throws RuntimeException Once removed.
     */
    public function native(): NSView
    {
        return $this->native ?? throw new RuntimeException("'{$this->path()}' is removed.");
    }

    protected function adoptNative(NSView $native): void
    {
        $native->setTranslatesAutoresizingMaskIntoConstraints(false);
        $this->native = $native;
    }

    /**
     * The container took the view out before this (detachNative); what is left is this
     * primitive's own: a running transition, and the view itself.
     */
    protected function releaseNative(): void
    {
        $this->stopTransition();
        $this->untrackCursor();
        $this->applyClickable(false);
        NSLayoutConstraint::deactivateConstraints($this->min_size_constraints);
        $this->min_size_constraints = [];
        $this->native->removeFromSuperview();
        $this->native = null;
    }

    /**
     * A hidden view takes no space: an NSStackView detaches it from its arrangement. A focused
     * view that is hidden loses first responder; AppKit moves it on (observed).
     */
    protected function applyVisible(bool $visible, ?TKTransition $transition, float $seconds): void
    {
        $this->stopTransition();

        if (is_null($transition) || $seconds <= 0.0) {
            $this->native->setHidden(! $visible);

            return;
        }

        if ($transition->moves()) {
            $this->parent() instanceof AppkitOverlay
                ? $this->playMove($visible, $transition, $seconds)
                : $this->native->setHidden(! $visible);

            return;
        }

        $this->playFade($visible, $seconds);
    }

    /**
     * A clickable group: AppKit's click recognizer on its view (primary button, one click)
     * that does not delay the press, so a control inside keeps its own click. Off, or at
     * release, the recognizer goes.
     */
    protected function applyClickable(bool $on): void
    {
        if ($on && is_null($this->click)) {
            $self = WeakReference::create($this);
            $this->click_target = new ObjCTarget(static function () use ($self): void {
                $self->get()?->clickedNatively();
            });
            $click = NSClickGestureRecognizer::initWithTargetAction($this->click_target, ObjCTarget::ACTION);
            $click->setButtonMask(1);
            $click->setNumberOfClicksRequired(1);
            $click->setDelaysPrimaryMouseButtonEvents(false);
            $this->native->addGestureRecognizer($click);
            $this->click = $click;

            return;
        }

        if (! $on && ! is_null($this->click)) {
            $this->native?->removeGestureRecognizer($this->click);
            $this->click = null;
            $this->click_target = null;
        }
    }

    /**
     * The recognizer acted: where, from the view's top-left (an unflipped view's y counts up
     * from its bottom).
     */
    private function clickedNatively(): void
    {
        if (is_null($this->click) || is_null($this->native)) {
            return;
        }

        $at = $this->click->locationInView($this->native);
        $y = $this->native->isFlipped() ? $at->y : $this->native->bounds()->height - $at->y;
        $this->groupClicked($at->x, $y);
    }

    /**
     * Opacity is the view's alphaValue. While a transition runs, its landing sets it.
     */
    protected function applyOpacity(float $opacity): void
    {
        if ($this->transition_timers !== []) {
            return;
        }

        $this->ownLayer();
        $this->native->setAlphaValue($opacity);
    }

    /**
     * makeFirstResponder's answer is not the outcome: it answers true for a view that takes no
     * focus, or is in no window, while the window keeps itself as first responder (observed).
     * So the view is checked first (in this window, accepting first responder) and the window's
     * first responder read after.
     */
    protected function nativeFocus(): bool
    {
        $view = $this->native;
        $window = $view?->window();

        if (is_null($window) || $window !== $this->window()->native() || ! $view->acceptsFirstResponder()) {
            return false;
        }

        $window->makeFirstResponder($view);

        return $this->hasFocus();
    }

    /**
     * Whether this view is its window's focus: the window's first responder is the view, or
     * the field editor (an NSTextView) editing it, whose delegate is the view. The window
     * need not be key.
     */
    public function hasFocus(): bool
    {
        if (is_null($this->native)) {
            return false;
        }

        $responder = $this->native->window()?->firstResponder();

        return $responder === $this->native
            || ($responder instanceof NSTextView && $responder->delegate() === $this->native);
    }

    /**
     * The view's frame: what it occupies on screen, in points. A bordered push button's frame
     * is larger than the alignment rect constraints place (its bezel inset); a borderless
     * button has no bezel, and its frame is its title and icon.
     *
     * @return array{int, int}
     */
    public function size(): array
    {
        if (is_null($this->native)) {
            return [0, 0];
        }
        $frame = $this->native->frame();

        return [(int) round($frame->width), (int) round($frame->height)];
    }

    /**
     * The layer's backgroundColor; null clears it, so the view draws as AppKit's own again.
     */
    protected function applyBackground(?Color $color): void
    {
        $this->ownLayer()?->setBackgroundColor(is_null($color) ? null : self::nsColor($color)->CGColor());
    }

    /**
     * One radius on every corner.
     */
    protected function applyCornerRadius(float $radius): void
    {
        $this->roundLayer(Corners::of($radius));
    }

    /**
     * The layer's cornerRadius is the largest radius, and maskedCorners names the corners that
     * have one: Core Animation takes one radius, so corners of different non-zero radii are
     * all rounded with the largest.
     */
    protected function applyCorners(Corners $corners): void
    {
        $this->roundLayer($corners);
    }

    /**
     * The view's clipsToBounds (and with it the layer's masksToBounds): what is inside is cut
     * at the edge and the rounded corners.
     */
    protected function applyClip(bool $clip): void
    {
        $this->ownLayer();
    }

    /**
     * Whether the layer cuts what is inside: as clip() says. A view whose own content is
     * larger than it (a covering picture) answers true always.
     */
    protected function masksContent(): bool
    {
        return $this->clips();
    }

    /**
     * Rounds the background and, while it clips, the content.
     */
    private function roundLayer(Corners $corners): void
    {
        $layer = $this->ownLayer();
        if (is_null($layer)) {
            return;
        }

        $layer->setCornerRadius($corners->largest());
        $layer->setMaskedCorners(self::cornerMask($corners, $this->native->isFlipped()));
        // AppKit turns a plain view's clipsToBounds on once its layer has a corner radius
        // (observed on NSView and NSStackView, not on NSTextField): set back as clip() says.
        $this->native->setClipsToBounds($this->masksContent());
    }

    /**
     * The view's layer, made on first use, cutting what is inside only as masksContent() says.
     * The view's clipsToBounds is what decides: AppKit sets the layer's masksToBounds from it
     * again when the layer changes (a masksToBounds set on the layer of an NSStackView went
     * back to true once its corner radius was set, observed), and an NSStackView clips by
     * default.
     */
    private function ownLayer(): ?CALayer
    {
        $this->native->setWantsLayer(true);
        $this->native->setClipsToBounds($this->masksContent());

        return $this->native->layer();
    }

    /**
     * The corners with a radius, in the layer's coordinates. A layer-backed view's layer has
     * the view's geometry: y grows upward in an unflipped view, so its top is MaxY; in a
     * flipped one the top is MinY (checked in pixels). Uniform corners mask all four.
     */
    private static function cornerMask(Corners $corners, bool $flipped): int
    {
        [$topLeft, $topRight, $bottomRight, $bottomLeft] = $flipped
            ? [CACornerMask::MIN_X_MIN_Y_CORNER, CACornerMask::MAX_X_MIN_Y_CORNER, CACornerMask::MAX_X_MAX_Y_CORNER, CACornerMask::MIN_X_MAX_Y_CORNER]
            : [CACornerMask::MIN_X_MAX_Y_CORNER, CACornerMask::MAX_X_MAX_Y_CORNER, CACornerMask::MAX_X_MIN_Y_CORNER, CACornerMask::MIN_X_MIN_Y_CORNER];

        $mask = 0;
        foreach ([[$corners->topLeft, $topLeft], [$corners->topRight, $topRight], [$corners->bottomRight, $bottomRight], [$corners->bottomLeft, $bottomLeft]] as [$radius, $corner]) {
            if ($corners->isUniform() || $radius > 0.0) {
                $mask |= $corner->value;
            }
        }

        return $mask;
    }

    /**
     * The container placed the child first (childLayoutChanged): nothing is on the view itself.
     */
    protected function applyFill(bool $horizontal, bool $vertical): void {}

    /**
     * As applyFill().
     */
    protected function applyAlign(Align $horizontal, Align $vertical): void {}

    /**
     * Width and height floors as required constraints. Inside a fixed the view is framed, not
     * constrained: the fixed sized it already (childLayoutChanged), to the larger of its frame
     * and this minimum.
     */
    protected function applyMinSize(int $width, int $height): void
    {
        if ($this->parent() instanceof AppkitFixed) {
            return;
        }

        NSLayoutConstraint::deactivateConstraints($this->min_size_constraints);
        $this->min_size_constraints = [
            $this->native->widthAnchor()->constraintGreaterThanOrEqualToConstant((float) $width),
            $this->native->heightAnchor()->constraintGreaterThanOrEqualToConstant((float) $height),
        ];
        NSLayoutConstraint::activateConstraints($this->min_size_constraints);
    }

    protected function applyTooltip(?string $tooltip): void
    {
        $this->native->setToolTip($tooltip);
    }

    /**
     * On the layer, as the background is: inside the view's bounds, over what it draws. A null
     * colour is the system's separator colour, as it resolves now (a layer keeps a CGColor,
     * which does not follow a later change of appearance).
     */
    protected function applyBorder(int $width, ?Color $color): void
    {
        $layer = $this->ownLayer();
        if (is_null($layer)) {
            return;
        }

        $layer->setBorderWidth((float) $width);
        $layer->setBorderColor((is_null($color) ? NSColor::separatorColor() : self::nsColor($color))->CGColor());
    }

    /**
     * Null takes the area away: the view shows AppKit's own pointer again. MOVE has no AppKit
     * cursor, so it shows the view's own too.
     */
    protected function applyCursor(?Cursor $cursor): void
    {
        $this->untrackCursor();

        $shape = is_null($cursor) ? null : self::nsCursor($cursor);
        if (! is_null($shape)) {
            $this->cursor_area = AppkitPointer::track($this->native, $shape);
        }
    }

    /**
     * AppKit's own shape for each; null where AppKit has none.
     */
    protected static function nsCursor(Cursor $cursor): ?NSCursor
    {
        return match ($cursor) {
            Cursor::ARROW => NSCursor::arrowCursor(),
            Cursor::POINTER => NSCursor::pointingHandCursor(),
            Cursor::TEXT => NSCursor::IBeamCursor(),
            Cursor::CROSSHAIR => NSCursor::crosshairCursor(),
            Cursor::GRAB => NSCursor::openHandCursor(),
            Cursor::GRABBING => NSCursor::closedHandCursor(),
            Cursor::NOT_ALLOWED => NSCursor::operationNotAllowedCursor(),
            Cursor::RESIZE_HORIZONTAL => NSCursor::resizeLeftRightCursor(),
            Cursor::RESIZE_VERTICAL => NSCursor::resizeUpDownCursor(),
            Cursor::RESIZE_NWSE => NSCursor::frameResizeCursorFromPositionInDirections(NSCursorFrameResizePosition::TOP_LEFT, NSCursorFrameResizeDirections::ALL),
            Cursor::RESIZE_NESW => NSCursor::frameResizeCursorFromPositionInDirections(NSCursorFrameResizePosition::TOP_RIGHT, NSCursorFrameResizeDirections::ALL),
            Cursor::MOVE => null,
        };
    }

    private function untrackCursor(): void
    {
        if (! is_null($this->cursor_area) && ! is_null($this->native)) {
            AppkitPointer::untrack($this->native, $this->cursor_area);
        }
        $this->cursor_area = null;
    }

    protected static function nsColor(Color $color): NSColor
    {
        return NSColor::colorWithRedGreenBlueAlpha($color->red, $color->green, $color->blue, $color->alpha);
    }

    /**
     * Appearing: shown at alpha 0, then one frame later (AppKit animates from what it last
     * drew) a group fades it to opacity(). Disappearing: a group fades it to 0, then the
     * landing hides it and puts its alpha back. The group runs in Core Animation; the timers
     * only start and land it.
     */
    private function playFade(bool $visible, float $seconds): void
    {
        $view = $this->native;
        $this->ownLayer();

        $fade = static function (self $primitive, float $to) use ($seconds): void {
            NSAnimationContext::beginGrouping();
            $context = NSAnimationContext::currentContext();
            $context->setDuration($seconds);
            $context->setAllowsImplicitAnimation(true);
            $primitive->native->setAlphaValue($to);
            NSAnimationContext::endGrouping();
        };

        if ($visible) {
            $lead = 1 / 30;
            $view->setAlphaValue(0.0);
            $view->setHidden(false);
            $this->after($lead, static fn (self $primitive) => $fade($primitive, $primitive->opacity()));
            $this->after($lead + $seconds, static fn (self $primitive) => $primitive->landTransition());

            return;
        }

        $fade($this, 0.0);
        $this->after($seconds, static function (self $primitive): void {
            $primitive->native->setHidden(true);
            $primitive->landTransition();
        });
    }

    /**
     * A move in an overlay, no fade. The overlay holds the child's placement for the move and
     * lays it out, which gives its size. Appearing: placed one own width or height out toward
     * the edge, then, one frame later, a group on the move's curve (Topside's MOVE_IN_CURVE,
     * easeOutQuint) brings its constraints back to 0. Disappearing: a group on MOVE_OUT_CURVE
     * (easeInQuint, the same motion backwards) takes them out to the edge, and the landing
     * hides it. AppKit animates the frames the group's layout pass sets.
     */
    private function playMove(bool $visible, TKTransition $transition, float $seconds): void
    {
        /** @var AppkitOverlay $overlay */
        $overlay = $this->parent();
        [$edgeX, $edgeY] = $transition->edge();

        $this->native->setAlphaValue($this->opacity());
        if ($visible) {
            $this->native->setHidden(false);
        }
        $this->moving_in = $overlay;
        [$width, $height] = $overlay->holdForMove($this);
        $out = [$edgeX * $width, $edgeY * $height];

        $curve = CAMediaTimingFunction::functionWithControlPoints(...$transition->curve($visible));
        $travel = static function (self $primitive) use ($overlay, $visible, $out, $seconds, $curve): void {
            if ($overlay->isRemoved()) {
                return;
            }
            NSAnimationContext::beginGrouping();
            $context = NSAnimationContext::currentContext();
            $context->setDuration($seconds);
            $context->setAllowsImplicitAnimation(true);
            $context->setTimingFunction($curve);
            $visible ? $overlay->offsetMove($primitive, 0.0, 0.0) : $overlay->offsetMove($primitive, $out[0], $out[1]);
            $overlay->native()->layoutSubtreeIfNeeded();
            NSAnimationContext::endGrouping();
        };
        $land = static function (self $primitive) use ($visible): void {
            if (! $visible) {
                $primitive->native->setHidden(true);
            }
            $primitive->endMove();
            $primitive->landTransition();
        };

        if (! $visible) {
            $travel($this);
            $this->after($seconds, $land);

            return;
        }

        // Placed out at the edge first; one frame later (AppKit animates from what it last
        // drew, as for the fade) the group brings it in.
        $overlay->offsetMove($this, $out[0], $out[1]);
        $overlay->native()->layoutSubtreeIfNeeded();
        $lead = 1 / 30;
        $this->after($lead, $travel);
        $this->after($lead + $seconds, $land);
    }

    /**
     * The overlay places the child as it is again.
     */
    private function endMove(): void
    {
        $overlay = $this->moving_in;
        $this->moving_in = null;
        $overlay?->releaseMove($this);
    }

    /**
     * Run $then with this primitive $seconds from now on the main run loop, unless the
     * transition stops first. The target holds the primitive weakly.
     *
     * @param \Closure(self): void $then
     */
    private function after(float $seconds, \Closure $then): void
    {
        $self = WeakReference::create($this);
        $target = new ObjCTarget(static function () use ($self, $then): void {
            $primitive = $self->get();
            if (! is_null($primitive) && ! is_null($primitive->native)) {
                $then($primitive);
            }
        });
        $timer = NSTimer::scheduledTimerWithTimeIntervalTargetSelectorUserInfoRepeats($seconds, $target, ObjCTarget::ACTION, null, false);
        $this->transition_timers[] = [$timer, $target];
    }

    /**
     * The transition has run: its timers are spent, and the alpha is the primitive's opacity.
     */
    private function landTransition(): void
    {
        $this->transition_timers = [];
        $this->native->setAlphaValue($this->opacity());
    }

    /**
     * A transition still running stops: its timers are invalidated and the view goes back
     * to the primitive's opacity. The visibility is whatever the caller sets next.
     */
    private function stopTransition(): void
    {
        if ($this->transition_timers === []) {
            return;
        }
        $this->endMove();

        foreach ($this->transition_timers as [$timer]) {
            $timer->invalidate();
        }
        $this->transition_timers = [];

        $this->native?->setAlphaValue($this->opacity());
    }
}
