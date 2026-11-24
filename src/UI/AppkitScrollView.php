<?php

namespace Jovian\Toolkits\Appkit\UI;

use NSRect;
use NSScrollView;
use NSLayoutConstraint;
use Surface\Toolkits\UI\TKPrimitive;
use Surface\Toolkits\UI\TKScrollView;
use Jovian\Toolkits\Appkit\UI\Concerns\AppkitNative;
use Jovian\Toolkits\Appkit\UI\Concerns\StretchesChildren;
use Jovian\Toolkits\Appkit\UI\Concerns\CarriesEnabledDown;

/**
 * An NSScrollView. The document is pinned to the clip view's top, at least as tall as it
 * when it scrolls down, and as wide as it unless it scrolls across.
 */
class AppkitScrollView extends TKScrollView
{
    use AppkitNative {
        releaseNative as private releaseView;
    }
    use CarriesEnabledDown;
    use StretchesChildren;

    /** @var list<NSLayoutConstraint> */
    private array $document = [];

    protected function bootstrapNative(): void
    {
        $scroll = NSScrollView::initWithFrame(new NSRect(0.0, 0.0, 0.0, 0.0));
        $scroll->setDrawsBackground(false);
        $this->adoptNative($scroll);
        $this->applyScrollbars($this->scrollsAcross(), $this->scrollsDown());
    }

    protected function attachNative(TKPrimitive $child): void
    {
        $this->scrollNative()->setDocumentView($child->native());
        $this->pin($child);
        $this->tellExpansion();
    }

    protected function detachNative(TKPrimitive $child): void
    {
        NSLayoutConstraint::deactivateConstraints($this->document);
        $this->document = [];
        $this->scrollNative()->setDocumentView(null);
        $this->tellExpansion();
    }

    protected function applyScrollbars(bool $horizontal, bool $vertical): void
    {
        $this->scrollNative()->setHasHorizontalScroller($horizontal);
        $this->scrollNative()->setHasVerticalScroller($vertical);

        $content = $this->content();
        if (! is_null($content)) {
            $this->pin($content);
        }
    }

    protected function childLayoutChanged(TKPrimitive $child): void
    {
        $this->tellExpansion();
    }

    protected function childVisibilityChanged(TKPrimitive $child): void
    {
        $this->tellExpansion();
    }

    protected function releaseNative(): void
    {
        NSLayoutConstraint::deactivateConstraints($this->document);
        $this->document = [];
        $this->releaseView();
    }

    private function pin(TKPrimitive $child): void
    {
        NSLayoutConstraint::deactivateConstraints($this->document);

        $view = $child->native();
        $clip = $this->scrollNative()->contentView();
        $constraints = [
            $view->leadingAnchor()->constraintEqualToAnchor($clip->leadingAnchor()),
            $view->topAnchor()->constraintEqualToAnchor($clip->topAnchor()),
        ];
        foreach ([[$this->scrollsAcross(), $view->widthAnchor(), $clip->widthAnchor()], [$this->scrollsDown(), $view->heightAnchor(), $clip->heightAnchor()]] as [$scrolls, $length, $room]) {
            if (! $scrolls) {
                $constraints[] = $length->constraintEqualToAnchor($room);
                continue;
            }
            $constraints[] = $length->constraintGreaterThanOrEqualToAnchor($room);
            // No larger than the view unless what it holds is.
            $snug = $length->constraintEqualToAnchor($room);
            $snug->setPriority(100.0);
            $constraints[] = $snug;
        }

        NSLayoutConstraint::activateConstraints($constraints);
        $this->document = $constraints;
    }

    private function scrollNative(): NSScrollView
    {
        /** @var NSScrollView */
        return $this->native();
    }
}
