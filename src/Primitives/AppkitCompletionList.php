<?php

namespace Jovian\Toolkits\Appkit\Primitives;

use Closure;
use NSBackingStoreType;
use NSIndexSet;
use NSObject;
use NSRect;
use NSScrollView;
use NSTableColumn;
use NSTableView;
use NSView;
use NSWindow;
use NSWindowOrderingMode;
use NSWindowStyleMask;
use ObjCDelegate;
use ObjCTarget;
use WeakReference;

/**
 * A combo's completion list: a borderless child window under the field holding a table of
 * rows. The child window never becomes key (a borderless window cannot), so the field editor
 * keeps focus and typing goes on; the field's delegate moves the highlight and chooses. A click on
 * a row is the table's action.
 */
final class AppkitCompletionList
{
    private const int MAX_ROWS = 8;

    private ?NSWindow $panel = null;

    private ?NSWindow $parent = null;

    private NSTableView $table;

    private ObjCDelegate $source;

    private ObjCTarget $target;

    /** @var list<string> */
    private array $rows = [];

    /**
     * @param Closure(int): void $chosen called with the row a click chose; static, holding nothing strongly
     */
    public function __construct(Closure $chosen)
    {
        $this->table = NSTableView::initWithFrame(new NSRect(0.0, 0.0, 0.0, 0.0));
        $this->table->addTableColumn(NSTableColumn::initWithIdentifier('row'));
        $this->table->setHeaderView(null);
        $this->table->setRefusesFirstResponder(true);
        // The data source and target hold their callables where PHP's collector cannot see them: a weak reference keeps them from holding the list.
        $self = WeakReference::create($this);
        $this->source = new ObjCDelegate('NSTableViewDataSource');
        $this->source->on('numberOfRowsInTableView:', static fn (): int => count($self->get()?->rows ?? []));
        $this->source->on('tableView:objectValueForTableColumn:row:', static fn (NSObject $table, NSTableColumn $column, int $row): string => $self->get()?->rows[$row] ?? '');
        $this->table->setDataSource($this->source);
        $table = WeakReference::create($this->table);
        $this->target = new ObjCTarget(static function () use ($table, $chosen): void {
            $row = $table->get()?->clickedRow() ?? -1;
            if ($row >= 0) {
                $chosen($row);
            }
        });
        $this->table->setTarget($this->target);
        $this->table->setAction(ObjCTarget::ACTION);
    }

    /**
     * Show $rows under $field, as wide as it; the highlight starts on no row.
     *
     * @param list<string> $rows
     */
    public function show(NSView $field, array $rows): void
    {
        $window = $field->window();
        if (is_null($window)) {
            return;
        }
        $panel = $this->panel ??= $this->makePanel();
        $below = $window->convertRectToScreen($field->convertRectToView($field->bounds(), null));
        // A scroll view keeps its table at least as tall as what it shows: shrink the panel
        // first, so the table's height is its rows', not a longer list's before. The table
        // insets its rows, so eight rows are measured as eight, then all of them go in.
        $panel->setFrameDisplay(new NSRect($below->x, $below->y, $below->width, 1.0), false);
        $this->rows = array_slice($rows, 0, self::MAX_ROWS);
        $this->table->reloadData();
        $height = $this->table->frame()->height;
        $this->rows = $rows;
        $this->table->reloadData();
        $this->table->deselectAll(null);
        $this->table->tableColumns()[0]->setWidth($below->width);
        $panel->setFrameDisplay(new NSRect($below->x, $below->y - $height - 2.0, $below->width, $height + 2.0), true);
        if ($this->parent !== $window) {
            $this->detach();
            $window->addChildWindowOrdered($panel, NSWindowOrderingMode::ABOVE);
            $this->parent = $window;
        }
    }

    /** The completions' panel, made on first show. */
    public function panel(): ?NSWindow
    {
        return $this->panel;
    }

    public function hide(): void
    {
        $this->detach();
        $this->rows = [];
    }

    private function detach(): void
    {
        if (is_null($this->parent) || is_null($this->panel)) {
            return;
        }
        $this->parent->removeChildWindow($this->panel);
        $this->panel->orderOut(null);
        $this->parent = null;
    }

    public function isShown(): bool
    {
        return ! is_null($this->parent);
    }

    /** @return list<string> */
    public function rows(): array
    {
        return $this->rows;
    }

    /** The highlighted row; -1 for none. */
    public function highlighted(): int
    {
        return $this->isShown() ? $this->table->selectedRow() : -1;
    }

    /** Move the highlight by $delta rows, clamped to the rows. */
    public function move(int $delta): void
    {
        if ($this->rows === []) {
            return;
        }
        $row = max(0, min(count($this->rows) - 1, $this->highlighted() + $delta));
        $this->table->selectRowIndexesByExtendingSelection(NSIndexSet::indexSetWithIndex($row), false);
        $this->table->scrollRowToVisible($row);
    }

    /** The field is gone: the window goes too. */
    public function close(): void
    {
        $this->hide();
        $this->table->setDataSource(null);
        $this->table->setTarget(null);
        $this->panel?->close();
        $this->panel = null;
    }

    private function makePanel(): NSWindow
    {
        $panel = NSWindow::initWithContentRectStyleMaskBackingDefer(new NSRect(0.0, 0.0, 10.0, 10.0), NSWindowStyleMask::BORDERLESS->value, NSBackingStoreType::BUFFERED, false);
        $panel->setReleasedWhenClosed(false);
        $panel->setHasShadow(true);
        $scroll = NSScrollView::initWithFrame(new NSRect(0.0, 0.0, 10.0, 10.0));
        $scroll->setDocumentView($this->table);
        $scroll->setHasVerticalScroller(true);
        $panel->setContentView($scroll);

        return $panel;
    }
}
