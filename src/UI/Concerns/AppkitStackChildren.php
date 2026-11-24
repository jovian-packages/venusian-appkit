<?php

namespace Jovian\Toolkits\Appkit\UI\Concerns;

use NSView;
use NSEdgeInsets;
use NSStackView;
use NSLayoutAttribute;
use NSLayoutConstraint;
use NSStackViewDistribution;
use NSLayoutConstraintOrientation;
use NSUserInterfaceLayoutOrientation;
use Surface\Toolkits\UI\TKPrimitive;
use Surface\Toolkits\UI\TKPrimitiveGroup;
use Surface\Contracts\Toolkits\UI\Align;

/**
 * What an AppKit column and row share: an NSStackView, built with the spacing; the padding is
 * its edge insets along the axis and the inset of each child's constraints across it.
 *
 * Fill and alignment are this stack's, as constraints it keeps per child (GTK's model):
 *  - Along the axis a child keeps its natural length (content hugging 750) unless it fills
 *    (hugging 1). While any shown child fills, the distribution is FILL and the fillers are
 *    tied to one length, so they share the spare space equally; otherwise it is GRAVITY_AREAS,
 *    children packed from the start with the spare space left empty.
 *  - Across the axis the stack sets no alignment of its own (NOT_AN_ATTRIBUTE): each child is
 *    pinned per its Align, inside the padding. FILL stretches it; START, CENTER and END keep
 *    its natural size.
 *  - A child fills when it, or anything shown inside it, fills, as GTK computes expand. A
 *    change of that ripples up to the containers above.
 *  - A view with no intrinsic size (a stack, a fixed) keeps its natural size through a low
 *    pull to zero (480), so what it holds sets its size; content hugging alone does nothing
 *    for it.
 *  - Along the axis, a filling child with START, CENTER or END there still takes its whole
 *    share: placing it inside that share would need a view around it.
 */
trait AppkitStackChildren
{
    use AppkitNative;
    use CarriesEnabledDown;

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
        $padding = (float) $this->padding;
        $stack->setEdgeInsets(new NSEdgeInsets($padding, $padding, $padding, $padding));
        $this->adoptNative($stack);
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
    }

    protected function applySpacing(int $spacing): void
    {
        $this->stackNative()->setSpacing((float) $spacing);
    }

    /**
     * fill() or align() changed on a child, or whether something inside it fills.
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
        $padding = (float) $this->padding;
        [$alignH, $alignV] = $child->aligned();
        $across = $vertical ? $alignH : $alignV;

        $constraints = [];
        // Along the axis: grows only while it fills.
        $pull = self::hug($view, ! $vertical, ! self::fillsAlong($child, ! $vertical));
        // Across the axis: grows only with FILL.
        $pullAcross = self::hug($view, $vertical, $across !== Align::FILL);
        foreach ([$pull, $pullAcross] as $made) {
            if (! is_null($made)) {
                $constraints[] = $made;
            }
        }

        [$start, $end, $center] = $vertical
            ? [$view->leadingAnchor(), $view->trailingAnchor(), $view->centerXAnchor()]
            : [$view->topAnchor(), $view->bottomAnchor(), $view->centerYAnchor()];
        [$stackStart, $stackEnd, $stackCenter] = $vertical
            ? [$stack->leadingAnchor(), $stack->trailingAnchor(), $stack->centerXAnchor()]
            : [$stack->topAnchor(), $stack->bottomAnchor(), $stack->centerYAnchor()];

        array_push($constraints, ...match ($across) {
            Align::FILL => [
                $start->constraintEqualToAnchorConstant($stackStart, $padding),
                $end->constraintEqualToAnchorConstant($stackEnd, -$padding),
            ],
            Align::START => [
                $start->constraintEqualToAnchorConstant($stackStart, $padding),
                $end->constraintLessThanOrEqualToAnchorConstant($stackEnd, -$padding),
            ],
            Align::END => [
                $start->constraintGreaterThanOrEqualToAnchorConstant($stackStart, $padding),
                $end->constraintEqualToAnchorConstant($stackEnd, -$padding),
            ],
            Align::CENTER => [
                $center->constraintEqualToAnchor($stackCenter),
                $start->constraintGreaterThanOrEqualToAnchorConstant($stackStart, $padding),
                $end->constraintLessThanOrEqualToAnchorConstant($stackEnd, -$padding),
            ],
        });

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

        $this->stackNative()->setDistribution($fillers === [] ? NSStackViewDistribution::GRAVITY_AREAS : NSStackViewDistribution::FILL);

        foreach (array_slice($fillers, 1) as $filler) {
            $tie = $vertical
                ? $filler->heightAnchor()->constraintEqualToAnchor($fillers[0]->heightAnchor())
                : $filler->widthAnchor()->constraintEqualToAnchor($fillers[0]->widthAnchor());
            // Above hugging (1), below compression resistance (750): each filler keeps its minimum.
            $tie->setPriority(200.0);
            $this->ties[] = $tie;
        }
        NSLayoutConstraint::activateConstraints($this->ties);

        $parent = $this->parent();
        if (! is_null($parent) && ! $this->isRemoved()) {
            $parent->childLayoutChanged($this);
        }
    }

    /**
     * Whether $child takes spare space along an axis: its own fill, or that of anything
     * shown inside it.
     */
    private static function fillsAlong(TKPrimitive $child, bool $horizontal): bool
    {
        if ($child->fills()[$horizontal ? 0 : 1]) {
            return true;
        }
        if ($child instanceof TKPrimitiveGroup) {
            foreach ($child->children() as $inner) {
                if (! $inner->isRemoved() && $inner->isVisible() && self::fillsAlong($inner, $horizontal)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Content hugging on one axis: 750 to keep the natural length, 1 to grow. A view with no
     * intrinsic length on that axis gets, while it keeps its natural length, a pull to zero at
     * 480 (returned, not yet active), below a window's size-stay-put (500), so its content
     * decides its length and a natural size never resizes a window.
     */
    private static function hug(NSView $view, bool $horizontal, bool $keep): ?NSLayoutConstraint
    {
        $orientation = $horizontal ? NSLayoutConstraintOrientation::HORIZONTAL : NSLayoutConstraintOrientation::VERTICAL;
        $priority = $keep ? 750.0 : 1.0;
        $view->setContentHuggingPriorityForOrientation($priority, $orientation);
        if ($view instanceof NSStackView) {
            // The stack pulls its edges in to its children at this priority. Just below a
            // child's own hugging (750), so a START or END child keeps its natural size and
            // the stack is what gives way; at 750 the two tie and AppKit stretches the child.
            $view->setHuggingPriorityForOrientation($keep ? 740.0 : $priority, $orientation);
        }

        $intrinsic = $view->intrinsicContentSize();
        if (! $keep || ($horizontal ? $intrinsic->width : $intrinsic->height) >= 0.0) {
            return null;
        }

        $pull = ($horizontal ? $view->widthAnchor() : $view->heightAnchor())->constraintEqualToConstant(0.0);
        $pull->setPriority(480.0);

        return $pull;
    }

    private function stackNative(): NSStackView
    {
        /** @var NSStackView */
        return $this->native();
    }
}
