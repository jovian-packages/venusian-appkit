<?php

namespace Jovian\Toolkits\Appkit\Primitives;

use Jovian\Toolkits\Appkit\Primitives\Concerns\AppkitPrimitive;
use NSApplication;
use NSColor;
use NSEvent;
use NSEventMask;
use NSIndexSet;
use NSNotification;
use NSObject;
use NSOutlineView;
use NSRect;
use NSScrollView;
use NSString;
use NSTableColumn;
use NSTextField;
use NSView;
use ObjCDelegate;
use ObjCTarget;
use Surface\Contracts\Windows\Mail\View\NodeActivated;
use Surface\Contracts\Windows\Mail\View\NodeExpanded;
use Surface\Contracts\Windows\Mail\View\NodeSelected;
use Surface\Contracts\Windows\Primitives\Placement;
use Surface\Contracts\Windows\Primitives\TableColumn;
use Surface\Contracts\Windows\Primitives\TreeNode;
use Surface\Contracts\Windows\ToolkitWindow;
use Surface\NutsAndBolts\Color;
use Surface\Windows\Primitives\TKPrimitiveGroup;
use Surface\Windows\Primitives\TKTree;
use WeakReference;

/**
 * An NSOutlineView in an NSScrollView (the native), one NSTableColumn per TableColumn, the first
 * the outline column. A data source answers from the nodes, its items one NSString per node id:
 * AppKit keys expansion and selection by the item object and does not retain items, so this
 * keeps them, and keeps the same one for an id across setNodes. A delegate makes each cell a
 * label with the node's text and colour.
 *
 * Every write the driver makes runs inside $applying, and the delegate's notifications are
 * dropped while it is set, so only the user's expand, collapse and selection post. Activation
 * is a double click (the outline's double action) or Return / Enter while the outline is its
 * window's first responder, which a local key-down monitor takes before AppKit does.
 */
class AppkitTree extends TKTree
{
    use AppkitPrimitive;

    protected NSOutlineView $outline;

    /**
     * Outline views hold their data source and delegate weakly: the primitive keeps both.
     */
    protected ObjCDelegate $source;

    protected ObjCDelegate $delegate;

    protected ObjCTarget $target;

    protected ?NSObject $monitor = null;

    protected readonly NSColor $built_background;

    /**
     * @var array<string, NSString> the item AppKit holds for each node id
     */
    protected array $items = [];

    /**
     * @var array<string, true> nodes AppKit closed with an ancestor and will reopen with it
     */
    private array $remembered = [];

    /** The event being handled when a node last opened: AppKit reopens remembered descendants inside it. */
    private ?NSEvent $opened_during = null;

    /** A driver write is in progress: the delegate's notifications are its echo. */
    private bool $applying = false;

    /** The node the native had selected after the last write or the last change the user made. */
    private ?string $known_selected = null;

    /**
     * @param list<TableColumn> $columns
     * @param list<TreeNode> $nodes
     */
    public function __construct(string $name, ToolkitWindow $window, ?TKPrimitiveGroup $parent, Placement $placement, array $columns, array $nodes)
    {
        parent::__construct($name, $window, $parent, $placement, $columns, $nodes);

        $this->outline = NSOutlineView::initWithFrame(new NSRect(0.0, 0.0, 0.0, 0.0));
        foreach ($this->columns as $column) {
            $native = NSTableColumn::initWithIdentifier($column->id);
            $native->setTitle($column->label);
            $this->outline->addTableColumn($native);
        }
        $this->outline->setOutlineTableColumn($this->outline->tableColumns()[0]);

        // The delegates, the target and the monitor hold their callables where PHP's collector cannot
        // see them: each reaches the tree through a weak reference, so a removed tree is freed.
        $self = WeakReference::create($this);

        $this->source = new ObjCDelegate('NSOutlineViewDataSource');
        $this->source->on('outlineView:numberOfChildrenOfItem:', static fn (NSObject $outline, ?NSString $item): int => count($self->get()?->childIds($item?->UTF8String()) ?? []));
        $this->source->on('outlineView:child:ofItem:', static fn (NSObject $outline, int $index, ?NSString $item): ?NSString => $self->get()?->childItem($item, $index));
        $this->source->on('outlineView:isItemExpandable:', static fn (NSObject $outline, ?NSString $item): bool => ! is_null($item) && ($self->get()?->childIds($item->UTF8String()) ?? []) !== []);
        $this->outline->setDataSource($this->source);

        $this->delegate = new ObjCDelegate('NSOutlineViewDelegate');
        $this->delegate->on('outlineView:viewForTableColumn:item:', static fn (NSObject $outline, ?NSTableColumn $column, NSString $item): ?NSView => $self->get()?->cell($column, $item));
        $this->delegate->on('outlineViewSelectionDidChange:', static fn () => $self->get()?->selectionChanged());
        $this->delegate->on('outlineViewItemDidExpand:', static fn (NSNotification $note) => $self->get()?->expansionChanged($note, true));
        $this->delegate->on('outlineViewItemDidCollapse:', static fn (NSNotification $note) => $self->get()?->expansionChanged($note, false));
        $this->outline->setDelegate($this->delegate);

        $this->target = new ObjCTarget(static fn () => $self->get()?->doubleClicked());
        $this->outline->setTarget($this->target);
        $this->outline->setDoubleAction(ObjCTarget::ACTION);
        $this->monitor = NSEvent::addLocalMonitorForEventsMatchingMaskHandler(
            NSEventMask::KEY_DOWN,
            static fn (NSEvent $event): ?NSEvent => is_null($tree = $self->get()) ? $event : $tree->keyDown($event),
        );

        $this->built_background = $this->outline->backgroundColor();
        $scroll = $this->adoptNative(NSScrollView::initWithFrame(new NSRect(0.0, 0.0, 0.0, 0.0)));
        $scroll->setDocumentView($this->outline);
        $scroll->setHasVerticalScroller(true);

        $this->reload();
    }

    public function native(): NSScrollView
    {
        return $this->native;
    }

    public function outline(): NSOutlineView
    {
        return $this->outline;
    }

    public function focusView(): NSOutlineView
    {
        return $this->outline;
    }

    /**
     * @param string $id
     * @return NSString|null the item AppKit holds for the node; null for an unknown id
     */
    public function item(string $id): ?NSString
    {
        return $this->items[$id] ?? null;
    }

    protected function applyNodes(array $nodes): void
    {
        $items = [];
        $walk = function (array $level) use (&$walk, &$items): void {
            foreach ($level as $node) {
                $items[$node->id] = $this->items[$node->id] ?? NSString::stringWithString($node->id);
                $walk($node->children);
            }
        };
        $walk($nodes);

        $this->quietly(function () use ($items): void {
            $this->outline->deselectAll(null);
            $this->items = $items;
            $this->outline->reloadData();
        });
        // reloadData() forgets the descendants AppKit would have reopened.
        $this->remembered = [];
        $this->known_selected = null;
        $this->remeasure();
    }

    protected function applyExpanded(string $id, bool $expanded): void
    {
        $this->quietly(function () use ($id, $expanded): void {
            if ($expanded) {
                $this->outline->expandItem($this->items[$id]);
            } else {
                $this->outline->collapseItem($this->items[$id]);
            }
        });
        $this->remeasure();
    }

    protected function applySelected(?string $id): void
    {
        $this->quietly(function () use ($id): void {
            if (is_null($id)) {
                $this->outline->deselectAll(null);

                return;
            }

            $row = $this->outline->rowForItem($this->items[$id]);
            $this->outline->selectRowIndexesByExtendingSelection(NSIndexSet::indexSetWithIndex($row), false);
            $this->outline->scrollRowToVisible($row);
        });
        $this->known_selected = $this->nativeSelected();
    }

    protected function nativeSelected(): ?string
    {
        $row = $this->outline->selectedRow();

        return $row < 0 ? null : $this->outline->itemAtRow($row)?->UTF8String();
    }

    protected function applyBackground(?Color $color): void
    {
        $this->outline->setBackgroundColor(is_null($color) ? $this->built_background : self::nsColor($color));
    }

    /**
     * The outline view's own size, which it keeps at its columns and visible rows, plus the header above it.
     *
     * @param bool $horizontal
     * @return float
     */
    protected function naturalLength(bool $horizontal): float
    {
        $outline = $this->outline->frame();
        if ($horizontal) {
            return $outline->width;
        }

        return $outline->height + ($this->outline->headerView()?->frame()->height ?? 0.0);
    }

    protected function applyNativeEnabled(bool $on): void
    {
        $this->outline->setEnabled($on);
    }

    protected function releaseNative(): void
    {
        if (! is_null($this->monitor)) {
            NSEvent::removeMonitor($this->monitor);
            $this->monitor = null;
        }
        $this->outline->setTarget(null);
        $this->outline->setDoubleAction(null);
        $this->outline->setDelegate(null);
        $this->outline->setDataSource(null);
    }

    /**
     * @param NSString|null $item null for the roots
     * @param int $index
     * @return NSString|null the item of the child at $index
     */
    protected function childItem(?NSString $item, int $index): ?NSString
    {
        return $this->items[$this->childIds($item?->UTF8String())[$index] ?? ''] ?? null;
    }

    /**
     * A label with the node's text in the column, in the node's colour for it or AppKit's own.
     *
     * @param NSTableColumn|null $column
     * @param NSString $item
     * @return NSView|null
     */
    protected function cell(?NSTableColumn $column, NSString $item): ?NSView
    {
        if (is_null($column)) {
            return null;
        }

        $node = $this->node($item->UTF8String());
        $at = array_search($column->identifier(), array_map(fn (TableColumn $c): string => $c->id, $this->columns), true);
        $label = NSTextField::labelWithString($this->cellTexts($node)[$at]);
        $color = $this->cellColors($node)[$at];
        if (! is_null($color)) {
            $label->setTextColor(self::nsColor($color));
        }

        return $label;
    }

    protected function selectionChanged(): void
    {
        if ($this->applying) {
            return;
        }

        $selected = $this->nativeSelected();
        if ($selected === $this->known_selected) {
            return;
        }

        $this->known_selected = $selected;
        $this->session()->post(new NodeSelected($this->window->name(), $this->path(), $this->uuid, $selected));
    }

    /**
     * AppKit closing a node first closes its expanded descendants, each with a notification, and
     * remembers them: opening the node again reopens them, again with notifications. Neither is
     * the user's doing. A descendant closed under a closed ancestor is remembered here; its
     * reopening posts nothing and is undone when the record has it collapsed (code collapsed it
     * while it was hidden). Anything else not inside a driver write is the user's: record it,
     * post it, and on opening re-apply the record below it.
     *
     * @param NSNotification $note
     * @param bool $expanded
     * @return void
     */
    protected function expansionChanged(NSNotification $note, bool $expanded): void
    {
        $id = $note->userInfo()['NSObject'] ?? null;
        if (! is_string($id) || ! isset($this->items[$id])) {
            return;
        }

        if (! $expanded && $this->ancestorClosed($id)) {
            $this->remembered[$id] = true;

            return;
        }
        $event = NSApplication::sharedApplication()->currentEvent();
        if ($expanded && isset($this->remembered[$id])) {
            unset($this->remembered[$id]);
            // AppKit reopens it inside the event that opened its ancestor. In a later event it is the
            // user's: AppKit had closed it for real (an Option-click collapse) and forgotten it.
            if ($event === $this->opened_during) {
                if (! isset($this->expanded[$id])) {
                    $this->quietly(fn () => $this->outline->collapseItem($this->items[$id]));
                }

                return;
            }
        }
        if ($expanded) {
            $this->opened_during = $event;
        }
        if ($this->applying || ! $this->nativeNodeExpanded($id, $expanded)) {
            return;
        }

        $this->session()->post(new NodeExpanded($this->window->name(), $this->path(), $this->uuid, $id, $expanded));
        if ($expanded) {
            $this->reopen($id);
        }
        $this->remeasure();
    }

    protected function doubleClicked(): void
    {
        $row = $this->outline->clickedRow();
        $id = $row < 0 ? null : $this->outline->itemAtRow($row)?->UTF8String();
        if (! is_null($id)) {
            $this->session()->post(new NodeActivated($this->window->name(), $this->path(), $this->uuid, $id));
        }
    }

    /**
     * Return or Enter in the outline's window while the outline is first responder and a node is
     * selected activates that node; the key goes no further. Any other key-down passes through.
     *
     * @param NSEvent $event
     * @return NSEvent|null
     */
    protected function keyDown(NSEvent $event): ?NSEvent
    {
        $window = $this->outline->window();
        if (is_null($window) || $event->window() !== $window || $window->firstResponder() !== $this->outline) {
            return $event;
        }
        if (! in_array($event->characters(), [mb_chr(NSCarriageReturnCharacter), mb_chr(NSEnterCharacter)], true)) {
            return $event;
        }

        $id = $this->nativeSelected();
        if (is_null($id)) {
            return $event;
        }

        $this->session()->post(new NodeActivated($this->window->name(), $this->path(), $this->uuid, $id));

        return null;
    }

    /**
     * @param string $id a node the outline holds
     * @return bool whether an ancestor of the node is closed in the outline
     */
    private function ancestorClosed(string $id): bool
    {
        $parent = null;
        foreach (array_slice($this->pathOf($id), 0, -1) as $index) {
            $parent = $this->childIds($parent)[$index];
            if (! $this->outline->isItemExpanded($this->items[$parent])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Run a driver write with the delegate's notifications dropped; a nested write keeps the guard.
     * The outermost write ends by taking the native's selection as known: a collapse can drop the
     * selected row without a notification the user's next selection could be told apart from.
     *
     * @param callable(): void $write
     * @return void
     */
    private function quietly(callable $write): void
    {
        $outer = $this->applying;
        $this->applying = true;
        try {
            $write();
        } finally {
            $this->applying = $outer;
            if (! $outer) {
                $this->known_selected = $this->nativeSelected();
            }
        }
    }
}
