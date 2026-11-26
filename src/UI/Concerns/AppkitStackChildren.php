<?php

namespace Jovian\Toolkits\Appkit\UI\Concerns;

use NSEdgeInsets;
use NSStackView;
use NSLayoutAttribute;
use NSLayoutConstraint;
use NSStackViewDistribution;
use NSUserInterfaceLayoutOrientation;
use Surface\Toolkits\UI\TKPrimitive;
use Surface\Contracts\Toolkits\UI\Align;
use Surface\Contracts\Toolkits\UI\Insets;

/**
 * What an AppKit column and row share: an NSStackView, built with the spacing; the padding is
 * its edge insets along the axis and the inset of each child's constraints across it.
 *
 * A child's margin is this stack's to keep, all of it native layout:
 *  - across the axis, added to the padding in the constants of the child's constraints;
 *  - between two shown children, the stack's custom spacing after the first: spacing, its
 *    margin after, and the next one's margin before;
 *  - before the first shown child and after the last, along the axis, added to the padding in
 *    the stack's edge insets.
 * Left and right are the leading and trailing sides, in the left-to-right layout AppKit uses
 * for a left-to-right language.
 *
 * Fill and alignment are this stack's, as constraints it keeps per child (GTK's model):
 *  - Along the axis a child keeps its natural length (content hugging 750) unless it fills
 *    (hugging 1). While any shown child fills, the distribution is FILL and the fillers are
 *    tied to one length, so they share the spare space equally; otherwise it is GRAVITY_AREAS,
 *    children packed from the start with the spare space left empty.
 *  - Across the axis the stack sets no alignment of its own (NOT_AN_ATTRIBUTE): each child is
 *    pinned per its Align, inside the padding. FILL stretches it; START, CENTER and END keep
 *    its natural size.
 *  - A child fills when Topside's expands() says so: it, or anything shown inside it, fills,
 *    as GTK computes expand (an overlay's covering child excepted). A change of that ripples
 *    up to the containers above.
 *  - A view with no intrinsic size (a stack, a fixed) keeps its natural size through a low
 *    pull to zero (480), so what it holds sets its size; content hugging alone does nothing
 *    for it.
 *  - Along the axis, a filling child with START, CENTER or END there still takes its whole
 *    share: placing it inside that share would need a view around it.
 *  - Homogeneous (setHomogeneous()): the distribution is FILL_EQUALLY and every shown child is
 *    tied to the first along the axis at 999 (FILL_EQUALLY's own ties give way to a wrapping
 *    label's compression and a view's pull to zero, measured), so every shown child has the
 *    same length and they fill the stack, whatever each one's natural length; the stack's
 *    natural length is the longest child's times their number. Hidden children are out of
 *    the arrangement, so they share nothing. Off, it is as above.
 *  - Across the axis, a child with a maximum width (column) or height (row) that wants its
 *    room (FILL-aligned, or filling that way) takes the room up to its maximum: a pull at 495
 *    toward the room's length under the required ceiling, placed by its Align (FILL read as
 *    START), so a CENTER child is centred once the room is wider.
 */
trait AppkitStackChildren
{
    use AppkitNative;
    use CarriesEnabledDown;
    use AppkitPlacesChildren;

    /**
     * Each child's constraints, by child uuid: where it sits across the axis, and the pulls
     * that stand in for hugging on a view with no intrinsic size.
     * @var array<string, list<NSLayoutConstraint>>
     */
    private array $placed = [];

    /**
     * Equal-length ties between the shown children that fill along the axis.
     * @var list<NSLayoutConstraint>
     */
    private array $ties = [];

    /**
     * Column: true, row: false.
     */
    abstract protected function isVertical(): bool;

    protected function bootstrapNative(): void
    {
        $stack = NSStackView::stackViewWithViews([]);
        $stack->setOrientation($this->isVertical() ? NSUserInterfaceLayoutOrientation::VERTICAL : NSUserInterfaceLayoutOrientation::HORIZONTAL);
        $stack->setDistribution(NSStackViewDistribution::GRAVITY_AREAS);
        $stack->setAlignment(NSLayoutAttribute::NOT_AN_ATTRIBUTE);
        $stack->setSpacing((float) $this->spacing());
        $this->adoptNative($stack);
        $this->space();
    }

    /**
     * At the end of the arrangement.
     */
    protected function attachNative(TKPrimitive $child): void
    {
        $this->stackNative()->addArrangedSubview($child->native());
        $this->place($child);
        $this->settle();
    }

    /**
     * Out of the arrangement and off this view: an arranged view stays a subview until it is
     * taken off too (observed). Its constraints go with it.
     */
    protected function detachNative(TKPrimitive $child): void
    {
        NSLayoutConstraint::deactivateConstraints($this->placed[$child->uuid()] ?? []);
        unset($this->placed[$child->uuid()]);

        $native = $child->native();
        $this->stackNative()->removeArrangedSubview($native);
        $native->removeFromSuperview();
        $this->settle();
    }

    /**
     * The children are already in their new order; the view goes to the same index in the
     * arrangement (hidden views stay arranged, so the indexes match).
     */
    protected function reorderNative(TKPrimitive $child, int $index): void
    {
        $native = $child->native();
        $this->stackNative()->removeArrangedSubview($native);
        $this->stackNative()->insertArrangedSubviewAtIndex($native, $index);
        $this->space();
    }

    protected function applySpacing(int $spacing): void
    {
        $this->stackNative()->setSpacing((float) $spacing);
        $this->space();
    }

    /**
     * The edge insets again, and every child placed again inside the new sides.
     */
    protected function applyPadding(Insets $padding): void
    {
        foreach ($this->children() as $child) {
            if (isset($this->placed[$child->uuid()])) {
                $this->place($child);
            }
        }
        $this->space();
    }

    /**
     * Every shown child the same length along the axis (NSStackViewDistribution FILL_EQUALLY
     * and ties at 999); off, the distribution follows the fillers again.
     */
    protected function applyHomogeneous(bool $on): void
    {
        $this->settle();
    }

    /**
     * fill(), align() or the margin changed on a child, or whether something inside it fills.
     */
    protected function childLayoutChanged(TKPrimitive $child): void
    {
        $this->place($child);
        $this->settle();
    }

    /**
     * A child was shown or hidden: whether it counts among the fillers changed. AppKit itself
     * takes a hidden view out of the arrangement; a fading-out child counts as hidden already.
     */
    protected function childVisibilityChanged(TKPrimitive $child): void
    {
        $this->settle();
    }

    /**
     * Hugging along both axes, and the constraints across the axis per the child's Align.
     */
    private function place(TKPrimitive $child): void
    {
        NSLayoutConstraint::deactivateConstraints($this->placed[$child->uuid()] ?? []);

        $vertical = $this->isVertical();
        $view = $child->native();
        $stack = $this->stackNative();
        [$alignH, $alignV] = $child->aligned();
        $across = $vertical ? $alignH : $alignV;
        // Padding and margin on the two sides across the axis.
        $padding = $this->padding();
        $margin = $child->margin();
        [$before, $after] = $vertical
            ? [(float) ($padding->left + $margin->left), (float) ($padding->right + $margin->right)]
            : [(float) ($padding->top + $margin->top), (float) ($padding->bottom + $margin->bottom)];

        $constraints = [];
        // Along the axis: grows only while it fills.
        $pull = self::hug($view, ! $vertical, ! self::fillsAlong($child, ! $vertical));
        // Across the axis: grows only with FILL, or up to its maximum where it wants its room.
        $maxAcross = $child->maximumSize()[$vertical ? 0 : 1];
        $capped = $maxAcross > 0 && ($across === Align::FILL || $child->fills()[$vertical ? 0 : 1]);
        $pullAcross = self::hug($view, $vertical, ! $capped && $across !== Align::FILL);
        foreach ([$pull, $pullAcross] as $made) {
            if (! is_null($made)) {
                $constraints[] = $made;
            }
        }

        // Across the axis, by its Align, inside the padding and margin on those two sides.
        if ($capped) {
            array_push($constraints, ...self::alignOn(self::anchorsOn($view, $vertical), self::anchorsOn($stack, $vertical), self::cappedAlign($across), $before, $after));
            $constraints[] = self::roomPull(self::lengthOn($view, $vertical), self::lengthOn($stack, $vertical), $before, $after);
        } else {
            array_push($constraints, ...self::alignOn(self::anchorsOn($view, $vertical), self::anchorsOn($stack, $vertical), $across, $before, $after));
        }

        NSLayoutConstraint::activateConstraints($constraints);
        $this->placed[$child->uuid()] = $constraints;
    }

    /**
     * The distribution and the ties follow the shown fillers; then the container above
     * places this one again, since whether it fills may have changed.
     */
    private function settle(): void
    {
        NSLayoutConstraint::deactivateConstraints($this->ties);
        $this->ties = [];

        $vertical = $this->isVertical();
        $fillers = [];
        foreach ($this->children() as $child) {
            if (! $child->isRemoved() && $child->isVisible() && isset($this->placed[$child->uuid()]) && self::fillsAlong($child, ! $vertical)) {
                $fillers[] = $child->native();
            }
        }

        $this->stackNative()->setDistribution(match (true) {
            $this->isHomogeneous() => NSStackViewDistribution::FILL_EQUALLY,
            $fillers === [] => NSStackViewDistribution::GRAVITY_AREAS,
            default => NSStackViewDistribution::FILL,
        });

        // Homogeneous: every shown child tied to the first at 999. FILL_EQUALLY's own ties sit
        // below a wrapping label's compression (490) and a view's pull to zero (480): alone it
        // left a long-text card wider and a short column shorter (measured).
        if ($this->isHomogeneous()) {
            $shown = [];
            foreach ($this->children() as $child) {
                if (! $child->isRemoved() && $child->isVisible() && isset($this->placed[$child->uuid()])) {
                    $shown[] = $child->native();
                }
            }
            foreach (array_slice($shown, 1) as $view) {
                $tie = $vertical
                    ? $view->heightAnchor()->constraintEqualToAnchor($shown[0]->heightAnchor())
                    : $view->widthAnchor()->constraintEqualToAnchor($shown[0]->widthAnchor());
                $tie->setPriority(999.0);
                $this->ties[] = $tie;
            }
        }

        foreach ($this->isHomogeneous() ? [] : array_slice($fillers, 1) as $filler) {
            $tie = $vertical
                ? $filler->heightAnchor()->constraintEqualToAnchor($fillers[0]->heightAnchor())
                : $filler->widthAnchor()->constraintEqualToAnchor($fillers[0]->widthAnchor());
            // Above hugging (1), below compression resistance (750): each filler keeps its minimum.
            $tie->setPriority(200.0);
            $this->ties[] = $tie;
        }
        NSLayoutConstraint::activateConstraints($this->ties);
        $this->space();

        $parent = $this->parent();
        if (! is_null($parent) && ! $this->isRemoved()) {
            $parent->childLayoutChanged($this);
        }
    }

    /**
     * The space along the axis: padding and the first and last shown child's outer margin as
     * the edge insets (across the axis the insets are the padding alone; the constraints keep
     * those sides), and between shown children the spacing plus the margins that face each
     * other as the custom spacing after the first. A hidden child is out of the arrangement,
     * so its margin counts nowhere.
     */
    private function space(): void
    {
        $stack = $this->stackNative();
        $vertical = $this->isVertical();
        $spacing = (float) $this->spacing();
        $padding = $this->padding();

        $shown = [];
        foreach ($this->children() as $child) {
            if (! $child->isRemoved() && isset($this->placed[$child->uuid()])) {
                if ($child->isVisible()) {
                    $shown[] = $child;
                } else {
                    $stack->setCustomSpacingAfterView($spacing, $child->native());
                }
            }
        }

        foreach ($shown as $at => $child) {
            $next = $shown[$at + 1] ?? null;
            $gap = is_null($next) ? $spacing : $spacing + self::marginAfter($child, $vertical) + self::marginBefore($next, $vertical);
            $stack->setCustomSpacingAfterView($gap, $child->native());
        }

        $first = $shown === [] ? 0 : self::marginBefore($shown[0], $vertical);
        $last = $shown === [] ? 0 : self::marginAfter($shown[count($shown) - 1], $vertical);
        $stack->setEdgeInsets($vertical
            ? new NSEdgeInsets((float) $padding->top + $first, (float) $padding->left, (float) $padding->bottom + $last, (float) $padding->right)
            : new NSEdgeInsets((float) $padding->top, (float) $padding->left + $first, (float) $padding->bottom, (float) $padding->right + $last));
    }

    /**
     * A child's margin on the side facing the start of the axis (top, or left).
     */
    private static function marginBefore(TKPrimitive $child, bool $vertical): int
    {
        return $vertical ? $child->margin()->top : $child->margin()->left;
    }

    /**
     * Its margin on the side facing the end of the axis (bottom, or right).
     */
    private static function marginAfter(TKPrimitive $child, bool $vertical): int
    {
        return $vertical ? $child->margin()->bottom : $child->margin()->right;
    }

    /**
     * Whether $child takes spare space along an axis, as Topside's expands() says: its own
     * fill, or that of anything shown inside it that passes it on (an overlay's covering child
     * does not).
     */
    private static function fillsAlong(TKPrimitive $child, bool $horizontal): bool
    {
        return $child->expands($horizontal);
    }

    private function stackNative(): NSStackView
    {
        /** @var NSStackView */
        return $this->native();
    }
}
