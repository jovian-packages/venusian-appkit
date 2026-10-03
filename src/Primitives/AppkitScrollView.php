<?php

namespace Jovian\Toolkits\Appkit\Primitives;

use Jovian\Toolkits\Appkit\Primitives\Concerns\AppkitPrimitive;
use NSLayoutConstraint;
use NSRect;
use NSScrollView;
use Surface\Contracts\Windows\Primitives\Placement;
use Surface\Contracts\Windows\Primitives\TKPrimitive as Child;
use Surface\Contracts\Windows\ToolkitWindow;
use Surface\Windows\Primitives\TKPrimitiveGroup;
use Surface\Windows\Primitives\TKScrollView;

/**
 * An NSScrollView whose document is its one container, pinned to the clip view's top and
 * leading edges: the top stays in view and stays put as the content grows. On an axis that
 * scrolls the document is at least the viewport; on one that does not it is exactly the
 * viewport, so its content wraps to it.
 */
class AppkitScrollView extends TKScrollView
{
    use AppkitPrimitive;

    /**
     * @var list<NSLayoutConstraint>
     */
    protected array $document = [];

    public function __construct(string $name, ToolkitWindow $window, ?TKPrimitiveGroup $parent, Placement $placement)
    {
        parent::__construct($name, $window, $parent, $placement);
        $scroll = $this->adoptNative(NSScrollView::initWithFrame(new NSRect()));
        $scroll->setHasHorizontalScroller($this->scroll_horizontal);
        $scroll->setHasVerticalScroller($this->scroll_vertical);
        $scroll->setDrawsBackground(false);
    }

    public function native(): NSScrollView
    {
        return $this->native;
    }

    protected function insertNative(Child $child): void
    {
        $this->native->setDocumentView(self::nativeOf($child));
        $this->placeChild($child);
        $child->syncNativeEnabled();
        $this->replaceInAncestors();
    }

    protected function applyScrollbars(bool $horizontal, bool $vertical): void
    {
        $this->native->setHasHorizontalScroller($horizontal);
        $this->native->setHasVerticalScroller($vertical);

        $content = $this->content();
        if (! is_null($content)) {
            $this->placeChild($content);
        }
    }

    /**
     * Pin the document to the clip view per the scrolling axes.
     *
     * @param Child $child
     * @return void
     */
    public function placeChild(Child $child): void
    {
        NSLayoutConstraint::deactivateConstraints($this->document);

        $view = self::nativeOf($child);
        $clip = $this->native->contentView();
        $this->document = [
            $view->leadingAnchor()->constraintEqualToAnchor($clip->leadingAnchor()),
            $view->topAnchor()->constraintEqualToAnchor($clip->topAnchor()),
            $this->scroll_horizontal
                ? $view->widthAnchor()->constraintGreaterThanOrEqualToAnchor($clip->widthAnchor())
                : $view->widthAnchor()->constraintEqualToAnchor($clip->widthAnchor()),
            $this->scroll_vertical
                ? $view->heightAnchor()->constraintGreaterThanOrEqualToAnchor($clip->heightAnchor())
                : $view->heightAnchor()->constraintEqualToAnchor($clip->heightAnchor()),
        ];
        NSLayoutConstraint::activateConstraints($this->document);
    }

    /**
     * @param Child $child
     * @return void
     */
    public function removeNative(Child $child): void
    {
        NSLayoutConstraint::deactivateConstraints($this->document);
        $this->document = [];
        $this->native->setDocumentView(null);
        $this->replaceInAncestors();
    }
}
