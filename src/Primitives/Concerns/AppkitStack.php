<?php

namespace Jovian\Toolkits\Appkit\Primitives\Concerns;

use NSEdgeInsets;
use NSLayoutAttribute;
use NSLayoutConstraint;
use NSStackView;
use NSStackViewDistribution;
use NSUserInterfaceLayoutOrientation;
use Surface\Contracts\Windows\Primitives\Align;
use Surface\Contracts\Windows\Primitives\TKPrimitive as Child;

/**
 * Column and row over NSStackView. With no filling child, gravity-area distribution keeps every
 * arranged view at its natural size, packed from the start, with the leftover space empty. With
 * one, fill distribution leaves no gap to keep, so the leftover goes to the filling children,
 * whose main-axis hugging is the lowest, and they share it equally (Qt's stretch 1 each). Under
 * gravity areas a filler would only tie with its stack's own gap hugging, and a nested filler
 * would lose that tie as often as win it. A filling child aligned START, CENTER or END on the main axis
 * is arranged as a slot that takes the space, with the child placed inside it. No stack
 * alignment: each child is placed across the axis by its own constraints, inside the padding.
 */
trait AppkitStack
{
    /**
     * Cross-axis constraints per child name.
     * @var array<string, list<NSLayoutConstraint>>
     */
    protected array $placed = [];

    /**
     * Equal-length ties between the children that fill the main axis.
     * @var list<NSLayoutConstraint>
     */
    protected array $ties = [];

    /**
     * Column: true, row: false.
     * @return bool
     */
    abstract protected function isVertical(): bool;

    protected function buildStack(int $spacing, int $padding): void
    {
        $stack = NSStackView::stackViewWithViews([]);
        $stack->setOrientation($this->isVertical() ? NSUserInterfaceLayoutOrientation::VERTICAL : NSUserInterfaceLayoutOrientation::HORIZONTAL);
        $stack->setDistribution(NSStackViewDistribution::GRAVITY_AREAS);
        $stack->setAlignment(NSLayoutAttribute::NOT_AN_ATTRIBUTE);
        $stack->setSpacing((float) $spacing);
        $stack->setEdgeInsets(new NSEdgeInsets((float) $padding, (float) $padding, (float) $padding, (float) $padding));
        $this->adoptNative($stack);
    }

    /**
     * At the child's index among the children, which is the end for a new child.
     *
     * @param Child $child
     * @return void
     */
    protected function insertNative(Child $child): void
    {
        $index = array_search($child->name(), array_keys($this->children), true);
        $this->native->insertArrangedSubviewAtIndex(self::nativeOf($child), $index);
        $this->placeChild($child);
        $child->syncNativeEnabled();
        $this->replaceInAncestors();
    }

    protected function applySpacing(int $spacing): void
    {
        $this->native->setSpacing((float) $spacing);
    }

    protected function applyOrder(Child $child, int $index): void
    {
        $this->native->removeArrangedSubview(self::nativeOf($child));
        $this->native->insertArrangedSubviewAtIndex(self::nativeOf($child), $index);
    }

    /**
     * Main axis: hug unless the child fills, in a slot when it fills but is aligned. Cross
     * axis: kept within the padding, then pinned per Align. Then the fillers are tied equal.
     *
     * @param Child $child
     * @return void
     */
    public function placeChild(Child $child): void
    {
        NSLayoutConstraint::deactivateConstraints($this->placed[$child->name()] ?? []);

        $vertical = $this->isVertical();
        $fills = $child->nativeFills(! $vertical);
        $main_align = $child->nativeAlign(! $vertical);
        $before = self::nativeOf($child);
        // Wrapping or unwrapping moves views out of the stack, which drops them from its arrangement.
        $child->nativeSlot($fills && $main_align !== Align::FILL ? $main_align : null, $vertical);
        if (self::nativeOf($child) !== $before) {
            if (in_array($before, $this->native->arrangedSubviews(), true)) {
                $this->native->removeArrangedSubview($before);
            }
            $this->native->insertArrangedSubviewAtIndex(self::nativeOf($child), array_search($child->name(), array_keys($this->children), true));
        }

        $align = $child->nativeAlign($vertical);
        $child->nativeHug(! $vertical, ! $fills);
        $child->nativeHug($vertical, $align !== Align::FILL);

        $view = self::nativeOf($child);
        $stack = $this->native;
        $padding = (float) $this->padding;
        [$start, $end, $center] = $vertical ? ['leadingAnchor', 'trailingAnchor', 'centerXAnchor'] : ['topAnchor', 'bottomAnchor', 'centerYAnchor'];

        $constraints = [
            $view->$start()->constraintGreaterThanOrEqualToAnchorConstant($stack->$start(), $padding),
            $view->$end()->constraintLessThanOrEqualToAnchorConstant($stack->$end(), -$padding),
            ...match ($align) {
                Align::FILL => [
                    $view->$start()->constraintEqualToAnchorConstant($stack->$start(), $padding),
                    $view->$end()->constraintEqualToAnchorConstant($stack->$end(), -$padding),
                ],
                Align::START => [$view->$start()->constraintEqualToAnchorConstant($stack->$start(), $padding)],
                Align::CENTER => [$view->$center()->constraintEqualToAnchor($stack->$center())],
                Align::END => [$view->$end()->constraintEqualToAnchorConstant($stack->$end(), -$padding)],
            },
        ];
        NSLayoutConstraint::activateConstraints($constraints);
        $this->placed[$child->name()] = $constraints;
        $this->tie();
    }

    /**
     * @param Child $child
     * @return void
     */
    public function removeNative(Child $child): void
    {
        NSLayoutConstraint::deactivateConstraints($this->placed[$child->name()] ?? []);
        unset($this->placed[$child->name()]);
        $this->native->removeArrangedSubview(self::nativeOf($child));
        $this->tie();
        $this->replaceInAncestors();
    }

    /**
     * Every live, visible child that fills the main axis takes the same length as the first,
     * at a priority above their hugging and below compression resistance, so the spare space
     * is shared equally and each still keeps its minimum. Fill distribution while any child
     * fills, gravity areas otherwise.
     * @return void
     */
    protected function tie(): void
    {
        NSLayoutConstraint::deactivateConstraints($this->ties);
        $this->ties = [];
        $vertical = $this->isVertical();
        $length = $vertical ? 'heightAnchor' : 'widthAnchor';

        $fillers = array_values(array_filter(
            $this->children,
            fn (Child $child): bool => ! $child->isRemoved() && $child->isVisible() && $child->nativeFills(! $vertical),
        ));
        $this->native->setDistribution($fillers === [] ? NSStackViewDistribution::GRAVITY_AREAS : NSStackViewDistribution::FILL);
        foreach (array_slice($fillers, 1) as $filler) {
            $tie = self::nativeOf($filler)->$length()->constraintEqualToAnchor(self::nativeOf($fillers[0])->$length());
            $tie->setPriority(200.0);
            $this->ties[] = $tie;
        }
        NSLayoutConstraint::activateConstraints($this->ties);
    }
}
