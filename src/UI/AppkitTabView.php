<?php

namespace Jovian\Toolkits\Appkit\UI;

use NSRect;
use NSSize;
use NSView;
use NSTabView;
use ObjCDelegate;
use NSTabViewItem;
use WeakReference;
use NSLayoutConstraint;
use Surface\Toolkits\UI\TKTabView;
use Surface\Toolkits\UI\TKPrimitive;
use Jovian\Toolkits\Appkit\UI\Concerns\AppkitNative;
use Jovian\Toolkits\Appkit\UI\Concerns\StretchesChildren;
use Jovian\Toolkits\Appkit\UI\Concerns\CarriesEnabledDown;

/**
 * An NSTabView; each page is pinned inside its item's view. NSTabView sizes that by frame,
 * so the pages give the tab view no size of their own: it is kept at least the strip and the
 * largest page. A page's margin is the constants of its pins inside the item's view.
 */
class AppkitTabView extends TKTabView
{
    use AppkitNative {
        releaseNative as private releaseView;
    }
    use CarriesEnabledDown;
    use StretchesChildren;

    private ?ObjCDelegate $delegate = null;

    /** @var array<string, NSTabViewItem> by the page's uuid */
    private array $items = [];

    /** @var list<NSLayoutConstraint> */
    private array $least = [];

    /** @var array<string, list<NSLayoutConstraint>> each page's pins, by its uuid */
    private array $pins = [];

    protected function bootstrapNative(): void
    {
        $tabs = NSTabView::initWithFrame(new NSRect(0.0, 0.0, 0.0, 0.0));

        $self = WeakReference::create($this);
        $this->delegate = new ObjCDelegate('NSTabViewDelegate');
        // NSTabView has no enabled state: a click on a disabled one's tab is turned down here.
        $this->delegate->on('tabView:shouldSelectTabViewItem:', static function () use ($self): int {
            $tabs = $self->get();

            return ! is_null($tabs) && ($tabs->isWriting() || $tabs->takesInput()) ? 1 : 0;
        });
        $this->delegate->on('tabView:didSelectTabViewItem:', static function (NSTabView $view, ?NSTabViewItem $item) use ($self): void {
            if (! is_null($item)) {
                $self->get()?->choseItem((string) $item->identifier());
            }
        });
        $tabs->setDelegate($this->delegate);
        $this->adoptNative($tabs);
    }

    protected function attachNative(TKPrimitive $child): void
    {
        $item = NSTabViewItem::initWithIdentifier($child->uuid());
        $item->setLabel($this->title($child->name()));
        $holder = NSView::initWithFrame(new NSRect(0.0, 0.0, 0.0, 0.0));
        $item->setView($holder);

        $page = $child->native();
        self::stretch($page);
        $holder->addSubview($page);

        $this->items[$child->uuid()] = $item;
        $this->pin($child);
        $this->tabsNative()->addTabViewItem($item);
        $this->fit();
        $this->tellExpansion();
    }

    protected function detachNative(TKPrimitive $child): void
    {
        NSLayoutConstraint::deactivateConstraints($this->pins[$child->uuid()] ?? []);
        unset($this->pins[$child->uuid()]);
        $this->tabsNative()->removeTabViewItem($this->items[$child->uuid()]);
        unset($this->items[$child->uuid()]);
        $child->native()->removeFromSuperview();
        $this->fit();
        $this->tellExpansion();
    }

    protected function reorderNative(TKPrimitive $child, int $index): void
    {
        $item = $this->items[$child->uuid()];
        $this->tabsNative()->removeTabViewItem($item);
        $this->tabsNative()->insertTabViewItemAtIndex($item, $index);
    }

    protected function applyTitle(TKPrimitive $page, string $title): void
    {
        $this->items[$page->uuid()]->setLabel($title);
        $this->fit();
    }

    protected function applySelected(TKPrimitive $page): void
    {
        $this->tabsNative()->selectTabViewItemWithIdentifier($page->uuid());
    }

    protected function childLayoutChanged(TKPrimitive $child): void
    {
        if (isset($this->items[$child->uuid()])) {
            $this->pin($child);
        }
        $this->fit();
        $this->tellExpansion();
    }

    protected function releaseNative(): void
    {
        $this->tabsNative()->setDelegate(null);
        $this->delegate = null;
        NSLayoutConstraint::deactivateConstraints([...$this->least, ...array_merge(...array_values($this->pins))]);
        $this->least = [];
        $this->pins = [];
        $this->releaseView();
    }

    private function choseItem(string $uuid): void
    {
        foreach ($this->children() as $index => $page) {
            if ($page->uuid() === $uuid) {
                $this->chosen($index);

                return;
            }
        }
    }

    /**
     * The page's edges to its item's view, in from its margin.
     */
    private function pin(TKPrimitive $page): void
    {
        NSLayoutConstraint::deactivateConstraints($this->pins[$page->uuid()] ?? []);

        $view = $page->native();
        $holder = $this->items[$page->uuid()]->view();
        $margin = $page->margin();
        $this->pins[$page->uuid()] = [
            $view->leadingAnchor()->constraintEqualToAnchorConstant($holder->leadingAnchor(), (float) $margin->left),
            $view->trailingAnchor()->constraintEqualToAnchorConstant($holder->trailingAnchor(), (float) -$margin->right),
            $view->topAnchor()->constraintEqualToAnchorConstant($holder->topAnchor(), (float) $margin->top),
            $view->bottomAnchor()->constraintEqualToAnchorConstant($holder->bottomAnchor(), (float) -$margin->bottom),
        ];
        NSLayoutConstraint::activateConstraints($this->pins[$page->uuid()]);
    }

    private function fit(): void
    {
        NSLayoutConstraint::deactivateConstraints($this->least);

        $strip = self::strip();
        [$width, $height] = [0.0, 0.0];
        foreach ($this->children() as $page) {
            if (isset($this->items[$page->uuid()])) {
                $size = $page->native()->fittingSize();
                $margin = $page->margin();
                [$width, $height] = [max($width, $size->width + $margin->left + $margin->right), max($height, $size->height + $margin->top + $margin->bottom)];
            }
        }

        $tabs = $this->tabsNative();
        $this->least = [
            $tabs->widthAnchor()->constraintGreaterThanOrEqualToConstant($strip->width + $width),
            $tabs->heightAnchor()->constraintGreaterThanOrEqualToConstant($strip->height + $height),
        ];
        foreach ($this->least as $least) {
            // Under a split's divider (489) and the window's size (500): a page grows neither.
            $least->setPriority(488.0);
        }
        NSLayoutConstraint::activateConstraints($this->least);
    }

    /**
     * What a tab view keeps around a page, measured once on one with a frame.
     */
    private static function strip(): NSSize
    {
        static $strip = null;
        if (is_null($strip)) {
            $tabs = NSTabView::initWithFrame(new NSRect(0.0, 0.0, 300.0, 200.0));
            $item = NSTabViewItem::initWithIdentifier('measure');
            $item->setView(NSView::initWithFrame(new NSRect(0.0, 0.0, 0.0, 0.0)));
            $tabs->addTabViewItem($item);
            $page = $item->view()->frame();
            $strip = new NSSize(300.0 - $page->width, 200.0 - $page->height);
        }

        return $strip;
    }

    private function tabsNative(): NSTabView
    {
        /** @var NSTabView */
        return $this->native();
    }
}
