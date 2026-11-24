<?php

namespace Jovian\Toolkits\Appkit\UI;

use NSRect;
use NSView;
use NSColor;
use NSEvent;
use NSObject;
use NSString;
use NSIndexSet;
use ObjCTarget;
use NSEventMask;
use NSTextField;
use NSScrollView;
use ObjCDelegate;
use NSApplication;
use NSOutlineView;
use NSTableColumn;
use WeakReference;
use NSNotification;
use Surface\NutsAndBolts\Color;
use Surface\Toolkits\UI\TKTree;
use Surface\Contracts\Toolkits\UI\TableColumn;
use Jovian\Toolkits\Appkit\UI\Concerns\AppkitNative;
use Jovian\Toolkits\Appkit\UI\Concerns\ScrollsInside;
use Jovian\Toolkits\Appkit\UI\Concerns\TakesInputFromAbove;

/**
 * An NSOutlineView over the node ids. AppKit closes and reopens open descendants with their
 * ancestor, each with a notification: those are told apart from the user's.
 */
class AppkitTree extends TKTree
{
    use ScrollsInside, AppkitNative {
        ScrollsInside::hasFocus insteadof AppkitNative;
        ScrollsInside::nativeFocus insteadof AppkitNative;
        releaseNative as private releaseView;
    }
    use TakesInputFromAbove;

    private ?NSOutlineView $outline = null;

    /**
     * Outline views hold their data source and delegate weakly: the primitive keeps both.
     */
    private ?ObjCDelegate $source = null;

    private ?ObjCDelegate $delegate = null;

    private ?ObjCTarget $target = null;

    protected ?NSObject $monitor = null;

    private ?NSColor $built_background = null;

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

    protected function bootstrapNative(): void
    {
        $outline = NSOutlineView::initWithFrame(new NSRect(0.0, 0.0, 0.0, 0.0));
        foreach ($this->columns as $column) {
            $native = NSTableColumn::initWithIdentifier($column->id);
            $native->setTitle($column->label);
            $outline->addTableColumn($native);
        }
        $outline->setOutlineTableColumn($outline->tableColumns()[0]);
        if (count($this->columns) === 1 && $this->columns[0]->label === '') {
            $outline->setHeaderView(null);
        }
        $this->outline = $outline;

        $self = WeakReference::create($this);
        $this->source = new ObjCDelegate('NSOutlineViewDataSource');
        $this->source->on('outlineView:numberOfChildrenOfItem:', static fn (NSObject $view, ?NSString $item): int => count($self->get()?->childIds($item?->UTF8String()) ?? []));
        $this->source->on('outlineView:child:ofItem:', static fn (NSObject $view, int $index, ?NSString $item): ?NSString => $self->get()?->childItem($item, $index));
        $this->source->on('outlineView:isItemExpandable:', static fn (NSObject $view, ?NSString $item): bool => ! is_null($item) && ($self->get()?->childIds($item->UTF8String()) ?? []) !== []);
        $outline->setDataSource($this->source);

        $this->delegate = new ObjCDelegate('NSOutlineViewDelegate');
        $this->delegate->on('outlineView:viewForTableColumn:item:', static fn (NSObject $view, ?NSTableColumn $column, NSString $item): ?NSView => $self->get()?->cell($column, $item));
        $this->delegate->on('outlineViewSelectionDidChange:', static function () use ($self): void {
            $self->get()?->selectionChanged();
        });
        $this->delegate->on('outlineViewItemDidExpand:', static function (NSNotification $note) use ($self): void {
            $self->get()?->expansionChanged($note, true);
        });
        $this->delegate->on('outlineViewItemDidCollapse:', static function (NSNotification $note) use ($self): void {
            $self->get()?->expansionChanged($note, false);
        });
        $outline->setDelegate($this->delegate);

        $this->target = new ObjCTarget(static function () use ($self): void {
            $self->get()?->doubleClicked();
        });
        $outline->setTarget($this->target);
        $outline->setDoubleAction(ObjCTarget::ACTION);
        $this->monitor = NSEvent::addLocalMonitorForEventsMatchingMaskHandler(
            NSEventMask::KEY_DOWN,
            static fn (NSEvent $event): ?NSEvent => is_null($tree = $self->get()) ? $event : $tree->keyDown($event),
        );

        $this->built_background = $outline->backgroundColor();
        $scroll = NSScrollView::initWithFrame(new NSRect(0.0, 0.0, 0.0, 0.0));
        $scroll->setDocumentView($outline);
        $scroll->setHasVerticalScroller(true);
        $this->adoptNative($scroll);
        self::leastSize($scroll);

        $this->write(fn () => $this->reload());
    }

    protected function focusTarget(): NSView
    {
        return $this->outline;
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

        $this->write(function () use ($items): void {
            $this->outline->deselectAll(null);
            $this->items = $items;
            $this->outline->reloadData();
        });
        // reloadData() forgets the descendants AppKit would have reopened.
        $this->remembered = [];
    }

    protected function applyExpanded(string $id, bool $expanded): void
    {
        $this->write(function () use ($id, $expanded): void {
            if ($expanded) {
                $this->outline->expandItem($this->items[$id]);
            } else {
                $this->outline->collapseItem($this->items[$id]);
            }
        });
    }

    protected function applySelected(?string $id): void
    {
        $this->write(function () use ($id): void {
            if (is_null($id)) {
                $this->outline->deselectAll(null);

                return;
            }

            $row = $this->outline->rowForItem($this->items[$id]);
            $this->outline->selectRowIndexesByExtendingSelection(NSIndexSet::indexSetWithIndex($row), false);
            $this->outline->scrollRowToVisible($row);
        });
    }

    private function selectedInNative(): ?string
    {
        $row = $this->outline->selectedRow();

        return $row < 0 ? null : $this->outline->itemAtRow($row)?->UTF8String();
    }

    protected function applyBackground(?Color $color): void
    {
        $this->outline->setBackgroundColor(is_null($color) ? $this->built_background : self::nsColor($color));
    }

    protected function applyNativeEnabled(bool $takes_input): void
    {
        $this->outline->setEnabled($takes_input);
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
        $this->releaseView();
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
        if (! $this->isWriting()) {
            $this->choseNode($this->selectedInNative());
        }
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
                    $this->write(fn () => $this->outline->collapseItem($this->items[$id]));
                }

                return;
            }
        }
        if ($expanded) {
            $this->opened_during = $event;
        }
        $this->openedNode($id, $expanded);
    }

    protected function doubleClicked(): void
    {
        $row = $this->outline->clickedRow();
        $id = $row < 0 ? null : $this->outline->itemAtRow($row)?->UTF8String();
        if (! is_null($id)) {
            $this->activatedNode($id);
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

        $id = $this->selectedInNative();
        if (is_null($id)) {
            return $event;
        }

        $this->activatedNode($id);

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
}
