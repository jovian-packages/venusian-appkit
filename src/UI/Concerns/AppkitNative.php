<?php

namespace Jovian\Toolkits\Appkit\UI\Concerns;

use NSView;
use NSColor;
use NSTimer;
use NSTextView;
use ObjCTarget;
use WeakReference;
use RuntimeException;
use NSAnimationContext;
use NSLayoutConstraint;
use Surface\NutsAndBolts\Color;
use Surface\Contracts\Toolkits\UI\Align;
use Surface\Contracts\Toolkits\UI\TKTransition;
use Jovian\Toolkits\Appkit\UI\AppkitFixed;

/**
 * What every AppKit primitive does the same way with its view.
 *
 * Layout: views use Auto Layout. The container that holds a child owns the constraints that
 * place it (a stack's fill and alignment, a fixed's frame), so applyFill() and applyAlign()
 * have nothing to set on the view itself: the container did it in childLayoutChanged().
 *
 * Styling: background and corner radius are the view's layer (made layer-backed on first use).
 * The corner radius rounds the background; masksToBounds stays as AppKit has it for the class
 * (an NSStackView's layer clips, a control's does not).
 *
 * Transitions: AppKit plays the fade natively (an NSAnimationContext group with implicit
 * animation, run by Core Animation); a one-shot NSTimer lands it. The slide part of a
 * TKTransition is not played on AppKit: an NSStackView positions its arranged views itself,
 * and the next layout pass puts back a frame moved from PHP (observed), so only the fade runs.
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

        $this->playFade($visible, $seconds);
    }

    /**
     * Opacity is the view's alphaValue. While a transition runs, its landing sets it.
     */
    protected function applyOpacity(float $opacity): void
    {
        if ($this->transition_timers !== []) {
            return;
        }

        $this->native->setWantsLayer(true);
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
     * The view's frame: what it occupies on screen, in points. A control's frame is larger
     * than the alignment rect constraints place (a push button's bezel inset).
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
        $this->native->setWantsLayer(true);
        $this->native->layer()?->setBackgroundColor(is_null($color) ? null : self::nsColor($color)->CGColor());
    }

    /**
     * Rounds the background; what is inside is not clipped (an NSStackView's layer masks to
     * its bounds by default, so masking is turned off).
     */
    protected function applyCornerRadius(float $radius): void
    {
        $this->native->setWantsLayer(true);
        $layer = $this->native->layer();
        $layer?->setCornerRadius($radius);
        $layer?->setMasksToBounds(false);
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
        $view->setWantsLayer(true);

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

        foreach ($this->transition_timers as [$timer]) {
            $timer->invalidate();
        }
        $this->transition_timers = [];

        $this->native?->setAlphaValue($this->opacity());
    }
}
