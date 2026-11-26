<?php

namespace Jovian\Toolkits\Appkit\UI;

use NSRect;
use NSView;
use NSTextView;
use NSLayoutConstraint;
use NSLayoutConstraintOrientation;
use Surface\Toolkits\UI\TKOverlay;
use Surface\Toolkits\UI\TKPrimitive;
use Surface\Contracts\Toolkits\UI\Align;
use Surface\Contracts\Toolkits\UI\Insets;
use Jovian\Toolkits\Appkit\UI\Concerns\AppkitNative;
use Jovian\Toolkits\Appkit\UI\Concerns\CarriesEnabledDown;
use Jovian\Toolkits\Appkit\UI\Concerns\AppkitPlacesChildren;

/**
 * A plain layer-backed NSView whose children are its subviews, in order: the first at the
 * back, each later one drawn above (AppKit draws subviews in their order).
 *
 * Each child is placed on both axes by its Align, inside the padding and its margin, with the
 * stack's constraints (AppkitPlacesChildren): a child that fills an axis is held to both edges
 * on it. A child sizes the overlay through those constraints, as it sizes a stack across its
 * axis; the overlay's own pull to zero comes from the container above it.
 *
 * A child that fills both ways covers the overlay and never sizes it: held to the four edges,
 * its compression resistance drops to 1 on both axes (put back as it was when it stops), so
 * its content cannot push the overlay, and a picture there drops its natural size (AppkitImage).
 *
 * A child with a maximum length on an axis where it fills or is FILL-aligned takes the room
 * up to its maximum (a 495 pull under the required ceiling), placed by its Align there (FILL
 * read as START).
 *
 * A hidden child sizes nothing: its constraints go while it is hidden (its frame is then
 * AppKit's to leave), and come back when it is shown.
 *
 * Moving a child re-adds it and the children after it as subviews in the new order (only
 * addSubview: is bound to raise a view); their constraints, which removal drops, are made
 * again, and a focus inside a re-added child is given back.
 *
 * A MOVE_* transition on a child plays here: while it runs the overlay holds the child's
 * placement (placed even while hidden, its constraint constants offset by the move), and the
 * child's own group animates the layout (AppkitNative::playMove). A visibility change or a
 * layout change during the move does not snap it back; the landing places it as it is.
 */
class AppkitOverlay extends TKOverlay
{
    use AppkitNative {
        releaseNative as private releaseView;
    }
    use CarriesEnabledDown;
    use AppkitPlacesChildren;

    /**
     * Each shown child's constraints, by uuid.
     * @var array<string, list<NSLayoutConstraint>>
     */
    private array $placed = [];

    /**
     * Each placed child's constraints against the overlay, with the constant each was made
     * with and its axis (true: horizontal), by uuid: what a move offsets.
     * @var array<string, list<array{NSLayoutConstraint, float, bool}>>
     */
    private array $bases = [];

    /**
     * The children a move is playing on, and the offset it has them at, by uuid.
     * @var array<string, array{float, float}>
     */
    private array $moving = [];

    /**
     * The compression resistance a covering child had before it covered, by uuid:
     * [horizontal, vertical].
     * @var array<string, array{float, float}>
     */
    private array $resistance = [];

    protected function bootstrapNative(): void
    {
        $view = NSView::initWithFrame(new NSRect(0.0, 0.0, 0.0, 0.0));
        $this->adoptNative($view);
        $view->setWantsLayer(true);
        $view->setClipsToBounds($this->masksContent());
    }

    /**
     * On top of the others.
     */
    protected function attachNative(TKPrimitive $child): void
    {
        $this->native()->addSubview($child->native());
        $this->place($child);
        $this->tellExpansion();
    }

    protected function detachNative(TKPrimitive $child): void
    {
        unset($this->moving[$child->uuid()]);
        $this->unplace($child);
        $this->uncover($child);
        $child->native()->removeFromSuperview();
        $this->tellExpansion();
    }

    /**
     * The children are already in their new order: the one moved and every one after it go
     * to the top again, in order, so each is above those before it.
     */
    protected function reorderNative(TKPrimitive $child, int $index): void
    {
        $window = $this->native()->window();
        $focus = $window?->firstResponder();
        if ($focus instanceof NSTextView && $focus->delegate() instanceof NSView) {
            $focus = $focus->delegate();
        }

        $children = array_values($this->children());
        foreach (array_slice($children, $index) as $raised) {
            $this->unplace($raised);
            $raised->native()->removeFromSuperview();
            $this->native()->addSubview($raised->native());
            $this->place($raised);
        }

        if ($focus instanceof NSView && $window?->firstResponder() !== $focus && $focus->window() === $window) {
            $window->makeFirstResponder($focus);
        }
    }

    protected function applyPadding(Insets $padding): void
    {
        foreach ($this->children() as $child) {
            $this->place($child);
        }
    }

    /**
     * fill(), align(), minSize() or the margin changed on a child, or what is inside it fills.
     */
    protected function childLayoutChanged(TKPrimitive $child): void
    {
        $this->place($child);
        $this->tellExpansion();
    }

    protected function childVisibilityChanged(TKPrimitive $child): void
    {
        if (! isset($this->moving[$child->uuid()])) {
            $this->place($child);
        }
        $this->tellExpansion();
    }

    /**
     * A move begins on $child: it is placed (even while hidden) and laid out.
     *
     * @internal AppkitNative::playMove()
     * @return array{float, float} its width and height
     */
    public function holdForMove(TKPrimitive $child): array
    {
        $this->moving[$child->uuid()] = [0.0, 0.0];
        $this->place($child);
        $this->native()->layoutSubtreeIfNeeded();
        $frame = $child->native()->frame();

        return [$frame->width, $frame->height];
    }

    /**
     * Offsets a moving child's constraints by $dx, $dy (top-left based: right and down).
     *
     * @internal AppkitNative::playMove()
     */
    public function offsetMove(TKPrimitive $child, float $dx, float $dy): void
    {
        if (! isset($this->moving[$child->uuid()])) {
            return;
        }
        $this->moving[$child->uuid()] = [$dx, $dy];
        foreach ($this->bases[$child->uuid()] ?? [] as [$constraint, $base, $horizontal]) {
            $constraint->setConstant($base + ($horizontal ? $dx : $dy));
        }
    }

    /**
     * The move is over (landed or stopped): the child is placed as it is now.
     *
     * @internal AppkitNative::endMove()
     */
    public function releaseMove(TKPrimitive $child): void
    {
        unset($this->moving[$child->uuid()]);
        if ($this->isRemoved()) {
            return;
        }
        $this->place($child);
        $this->tellExpansion();
    }

    protected function releaseNative(): void
    {
        foreach ($this->placed as $constraints) {
            NSLayoutConstraint::deactivateConstraints($constraints);
        }
        $this->placed = [];
        $this->bases = [];
        $this->moving = [];
        $this->resistance = [];
        $this->releaseView();
    }

    /**
     * Its constraints made again: on each axis its Align, or FILL where it fills, inside the
     * padding and margin; its hugging on each axis. A covering child resists nothing; a
     * hidden one is not placed.
     */
    private function place(TKPrimitive $child): void
    {
        $this->unplace($child);
        $moving = $this->moving[$child->uuid()] ?? null;
        if ($child->isRemoved() || (! $child->isVisible() && is_null($moving))) {
            return;
        }

        $view = $child->native();
        $overlay = $this->native();
        $padding = $this->padding();
        $margin = $child->margin();
        [$fillsH, $fillsV] = $child->fills();
        [$alignH, $alignV] = $child->aligned();

        $this->sizesOverlay($child) ? $this->uncover($child) : $this->cover($child);

        $constraints = [];
        $bases = [];
        foreach ([[true, $fillsH, $alignH, $padding->left + $margin->left, $padding->right + $margin->right], [false, $fillsV, $alignV, $padding->top + $margin->top, $padding->bottom + $margin->bottom]] as [$horizontal, $fills, $align, $before, $after]) {
            $max = $child->maximumSize()[$horizontal ? 0 : 1];
            $capped = $max > 0 && ($fills || $align === Align::FILL);
            $align = $capped ? self::cappedAlign($align) : ($fills ? Align::FILL : $align);
            $pull = self::hug($view, $horizontal, ! $capped && $align !== Align::FILL);
            if (! is_null($pull)) {
                $constraints[] = $pull;
            }
            if ($capped) {
                $constraints[] = self::roomPull(self::lengthOn($view, $horizontal), self::lengthOn($overlay, $horizontal), (float) $before, (float) $after);
            }
            foreach (self::alignOn(self::anchorsOn($view, $horizontal), self::anchorsOn($overlay, $horizontal), $align, (float) $before, (float) $after) as $edge) {
                $constraints[] = $edge;
                $bases[] = [$edge, $edge->constant(), $horizontal];
            }
        }

        NSLayoutConstraint::activateConstraints($constraints);
        $this->placed[$child->uuid()] = $constraints;
        $this->bases[$child->uuid()] = $bases;
        if (! is_null($moving)) {
            $this->offsetMove($child, $moving[0], $moving[1]);
        }
    }

    private function unplace(TKPrimitive $child): void
    {
        NSLayoutConstraint::deactivateConstraints($this->placed[$child->uuid()] ?? []);
        unset($this->placed[$child->uuid()], $this->bases[$child->uuid()]);
    }

    /**
     * A covering child's content cannot push the overlay: compression resistance 1 both ways.
     */
    private function cover(TKPrimitive $child): void
    {
        if (isset($this->resistance[$child->uuid()])) {
            return;
        }

        $view = $child->native();
        $this->resistance[$child->uuid()] = [
            $view->contentCompressionResistancePriorityForOrientation(NSLayoutConstraintOrientation::HORIZONTAL),
            $view->contentCompressionResistancePriorityForOrientation(NSLayoutConstraintOrientation::VERTICAL),
        ];
        $view->setContentCompressionResistancePriorityForOrientation(1.0, NSLayoutConstraintOrientation::HORIZONTAL);
        $view->setContentCompressionResistancePriorityForOrientation(1.0, NSLayoutConstraintOrientation::VERTICAL);
    }

    /**
     * The resistance it had before it covered.
     */
    private function uncover(TKPrimitive $child): void
    {
        if (! isset($this->resistance[$child->uuid()])) {
            return;
        }

        [$horizontal, $vertical] = $this->resistance[$child->uuid()];
        unset($this->resistance[$child->uuid()]);
        if ($child->isRemoved()) {
            return;
        }
        $view = $child->native();
        $view->setContentCompressionResistancePriorityForOrientation($horizontal, NSLayoutConstraintOrientation::HORIZONTAL);
        $view->setContentCompressionResistancePriorityForOrientation($vertical, NSLayoutConstraintOrientation::VERTICAL);
    }
}
