<?php

namespace Jovian\Toolkits\Appkit\UI\Concerns;

use NSView;
use NSLayoutConstraint;
use Surface\Toolkits\UI\TKPrimitive;

/**
 * For a container that takes a child as one view (a tab page, a split pane, a scroll view's
 * document): the child sits in a holder view of the container's, its four edges pinned inside
 * it by its margin, so the margin is the constants of those pins. A hidden child's pins have
 * none: its margin takes no room.
 */
trait PinsInMargin
{
    /**
     * $child's edges to $holder's, in from its margin. Active; the caller keeps them to drop.
     *
     * @return list<NSLayoutConstraint>
     */
    private static function pinInMargin(TKPrimitive $child, NSView $holder): array
    {
        $view = $child->native();
        $margin = $child->margin();
        [$top, $right, $bottom, $left] = $child->isVisible()
            ? [$margin->top, $margin->right, $margin->bottom, $margin->left]
            : [0, 0, 0, 0];

        $pins = [
            $view->leadingAnchor()->constraintEqualToAnchorConstant($holder->leadingAnchor(), (float) $left),
            $view->trailingAnchor()->constraintEqualToAnchorConstant($holder->trailingAnchor(), (float) -$right),
            $view->topAnchor()->constraintEqualToAnchorConstant($holder->topAnchor(), (float) $top),
            $view->bottomAnchor()->constraintEqualToAnchorConstant($holder->bottomAnchor(), (float) -$bottom),
        ];
        NSLayoutConstraint::activateConstraints($pins);

        return $pins;
    }
}
