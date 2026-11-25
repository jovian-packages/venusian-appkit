<?php

namespace Jovian\Toolkits\Appkit\UI\Concerns;

use NSView;
use NSStackView;
use NSLayoutAnchor;
use NSLayoutConstraint;
use NSLayoutConstraintOrientation;
use Surface\Contracts\Toolkits\UI\Align;

/**
 * How an AppKit container places a child on one axis, shared by the stacks and the overlay:
 * the child's edges against the container's per its Align, a space before and after it, and
 * its content hugging on that axis.
 */
trait AppkitPlacesChildren
{
    /**
     * The constraints (not yet active) that put a child's [start, end, centre] anchors inside
     * the container's on one axis: FILL stretches it between the two, START and END hold one
     * edge and keep the other inside, CENTER centres it in the room the two spaces leave.
     *
     * @param array{NSLayoutAnchor, NSLayoutAnchor, NSLayoutAnchor} $child
     * @param array{NSLayoutAnchor, NSLayoutAnchor, NSLayoutAnchor} $room
     * @return list<NSLayoutConstraint>
     */
    private static function alignOn(array $child, array $room, Align $align, float $before, float $after): array
    {
        [$start, $end, $center] = $child;
        [$roomStart, $roomEnd, $roomCenter] = $room;

        return match ($align) {
            Align::FILL => [
                $start->constraintEqualToAnchorConstant($roomStart, $before),
                $end->constraintEqualToAnchorConstant($roomEnd, -$after),
            ],
            Align::START => [
                $start->constraintEqualToAnchorConstant($roomStart, $before),
                $end->constraintLessThanOrEqualToAnchorConstant($roomEnd, -$after),
            ],
            Align::END => [
                $start->constraintGreaterThanOrEqualToAnchorConstant($roomStart, $before),
                $end->constraintEqualToAnchorConstant($roomEnd, -$after),
            ],
            Align::CENTER => [
                $center->constraintEqualToAnchorConstant($roomCenter, ($before - $after) / 2),
                $start->constraintGreaterThanOrEqualToAnchorConstant($roomStart, $before),
                $end->constraintLessThanOrEqualToAnchorConstant($roomEnd, -$after),
            ],
        };
    }

    /**
     * A view's [start, end, centre] anchors on one axis.
     *
     * @return array{NSLayoutAnchor, NSLayoutAnchor, NSLayoutAnchor}
     */
    private static function anchorsOn(NSView $view, bool $horizontal): array
    {
        return $horizontal
            ? [$view->leadingAnchor(), $view->trailingAnchor(), $view->centerXAnchor()]
            : [$view->topAnchor(), $view->bottomAnchor(), $view->centerYAnchor()];
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
}
