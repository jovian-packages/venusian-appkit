<?php

namespace Jovian\Toolkits\Appkit\Primitives;

use Jovian\Toolkits\Appkit\Primitives\Concerns\AppkitPrimitive;
use NSLayoutConstraint;
use NSRect;
use NSTabView;
use NSTabViewItem;
use NSView;
use ObjCDelegate;
use Surface\Contracts\Windows\Mail\View\TabSelected;
use Surface\Contracts\Windows\Primitives\Placement;
use Surface\Contracts\Windows\Primitives\TKColumn;
use Surface\Contracts\Windows\Primitives\TKPrimitive as Child;
use Surface\Contracts\Windows\ToolkitWindow;
use Surface\Windows\Primitives\TKPrimitiveGroup;
use Surface\Windows\Primitives\TKTabView;
use WeakReference;

/**
 * An NSTabView with one item per page, its identifier the page's name. NSTabView sizes an
 * item's view by its frame, so each page's column is pinned inside a plain holder view the
 * item shows. The delegate hears every selection, AppKit's own on an add or a removal
 * included; only the user's switches post TabSelected.
 */
class AppkitTabView extends TKTabView
{
    use AppkitPrimitive;

    protected ObjCDelegate $delegate;

    /** A hook is changing the selection: the delegate's call is ours, not the user's. */
    protected bool $quiet = false;

    /**
     * @var array<string, NSTabViewItem> page name => item
     */
    protected array $items = [];

    /**
     * @var array<string, list<NSLayoutConstraint>> page name => the pins inside its holder
     */
    protected array $pins = [];

    public function __construct(string $name, ToolkitWindow $window, ?TKPrimitiveGroup $parent, Placement $placement)
    {
        parent::__construct($name, $window, $parent, $placement);
        $tabs = $this->adoptNative(NSTabView::initWithFrame(new NSRect(0.0, 0.0, 0.0, 0.0)));
        // The delegate holds its callable where PHP's collector cannot see it: a weak reference keeps it from holding the primitive.
        $self = WeakReference::create($this);
        $this->delegate = new ObjCDelegate('NSTabViewDelegate');
        $this->delegate->on('tabView:didSelectTabViewItem:', static fn (NSTabView $view, ?NSTabViewItem $item) => $self->get()?->selectedByUser($item));
        $tabs->setDelegate($this->delegate);
    }

    public function native(): NSTabView
    {
        return $this->native;
    }

    protected function insertPage(TKColumn $page, string $title): void
    {
        $item = NSTabViewItem::initWithIdentifier($page->name());
        $item->setLabel($title);
        $item->setView(NSView::initWithFrame(new NSRect(0.0, 0.0, 0.0, 0.0)));
        $this->items[$page->name()] = $item;
        $this->placeChild($page);
        $this->quietly(fn () => $this->native->addTabViewItem($item));
        $page->syncNativeEnabled();
        $this->replaceInAncestors();
    }

    /**
     * Pin the page's view to its holder's edges; the page grows on both axes.
     *
     * @param Child $child
     * @return void
     */
    public function placeChild(Child $child): void
    {
        $holder = $this->items[$child->name()]->view();
        NSLayoutConstraint::deactivateConstraints($this->pins[$child->name()] ?? []);
        $view = self::nativeOf($child);
        if ($view->superview() !== $holder) {
            $view->removeFromSuperview();
            $holder->addSubview($view);
        }
        $this->pins[$child->name()] = [
            $view->leadingAnchor()->constraintEqualToAnchor($holder->leadingAnchor()),
            $view->trailingAnchor()->constraintEqualToAnchor($holder->trailingAnchor()),
            $view->topAnchor()->constraintEqualToAnchor($holder->topAnchor()),
            $view->bottomAnchor()->constraintEqualToAnchor($holder->bottomAnchor()),
        ];
        NSLayoutConstraint::activateConstraints($this->pins[$child->name()]);
        $child->nativeHug(true, false);
        $child->nativeHug(false, false);
    }

    /**
     * AppKit selects a neighbour when the selected page goes: that selection is ours.
     *
     * @param Child $child
     * @return void
     */
    public function removeNative(Child $child): void
    {
        $name = $child->name();
        NSLayoutConstraint::deactivateConstraints($this->pins[$name] ?? []);
        if (isset($this->items[$name])) {
            $item = $this->items[$name];
            $this->quietly(fn () => $this->native->removeTabViewItem($item));
        }
        unset($this->items[$name], $this->pins[$name]);
        $this->replaceInAncestors();
    }

    protected function applyTitle(TKColumn $page, string $title): void
    {
        $this->items[$page->name()]->setLabel($title);
    }

    protected function applySelect(TKColumn $page): void
    {
        $this->quietly(fn () => $this->native->selectTabViewItemWithIdentifier($page->name()));
    }

    protected function nativeSelected(): ?string
    {
        return $this->native->selectedTabViewItem()?->identifier();
    }

    protected function releaseNative(): void
    {
        $this->native->setDelegate(null);
    }

    protected function selectedByUser(?NSTabViewItem $item): void
    {
        if ($this->quiet || $this->removed || is_null($item)) {
            return;
        }

        $this->session()->post(new TabSelected($this->window->name(), $this->path(), $this->uuid, $item->identifier()));
    }

    private function quietly(\Closure $change): void
    {
        $was = $this->quiet;
        $this->quiet = true;
        try {
            $change();
        } finally {
            $this->quiet = $was;
        }
    }
}
