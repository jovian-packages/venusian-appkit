<?php

namespace Jovian\Toolkits\Appkit\Primitives;

use Jovian\Toolkits\Appkit\Primitives\Concerns\AppkitPrimitive;
use NSColor;
use NSIndexSet;
use NSObject;
use NSRect;
use NSScrollView;
use NSTableColumn;
use NSTableView;
use ObjCDelegate;
use Surface\Contracts\Windows\Mail\View\RowSelected;
use Surface\Contracts\Windows\Primitives\Placement;
use Surface\Contracts\Windows\Primitives\TableColumn;
use Surface\Contracts\Windows\ToolkitWindow;
use Surface\NutsAndBolts\Color;
use Surface\Windows\Primitives\TKPrimitiveGroup;
use Surface\Windows\Primitives\TKTable;
use WeakReference;

/**
 * An NSTableView in an NSScrollView (the native), one NSTableColumn per TableColumn, fed by
 * a data source answering from the normalised rows. A selection change the table reports
 * that differs from the one Surface holds is the user's: it posts RowSelected. One that
 * matches is the echo of selectRow() or setRows(), and posts nothing. Not filling, it stands
 * at the size of its header and rows; its background is the table view's.
 */
class AppkitTable extends TKTable
{
    use AppkitPrimitive;

    protected NSTableView $table;

    /**
     * Tables hold their data source and delegate weakly: the primitive keeps both.
     */
    protected ObjCDelegate $source;

    protected ObjCDelegate $delegate;

    protected readonly NSColor $built_background;

    /**
     * @param list<TableColumn> $columns
     * @param list<array<string, scalar|null>> $rows
     */
    public function __construct(string $name, ToolkitWindow $window, ?TKPrimitiveGroup $parent, Placement $placement, array $columns, array $rows)
    {
        parent::__construct($name, $window, $parent, $placement, $columns, $rows);

        $this->table = NSTableView::initWithFrame(new NSRect(0.0, 0.0, 0.0, 0.0));
        foreach ($this->columns as $column) {
            $native = NSTableColumn::initWithIdentifier($column->id);
            $native->setTitle($column->label);
            $this->table->addTableColumn($native);
        }

        // The data source and delegate hold their callables where PHP's collector cannot see them: a weak reference keeps them from holding the primitive.
        $self = WeakReference::create($this);
        $this->source = new ObjCDelegate('NSTableViewDataSource');
        $this->source->on('numberOfRowsInTableView:', static fn (): int => count($self->get()?->rows ?? []));
        $this->source->on('tableView:objectValueForTableColumn:row:', static fn (NSObject $table, NSTableColumn $column, int $row): string => $self->get()?->rows[$row][$column->identifier()] ?? '');
        $this->table->setDataSource($this->source);

        $this->delegate = new ObjCDelegate('NSTableViewDelegate');
        $this->delegate->on('tableViewSelectionDidChange:', static fn () => $self->get()?->selectionChanged());
        $this->table->setDelegate($this->delegate);

        $this->built_background = $this->table->backgroundColor();
        $scroll = $this->adoptNative(NSScrollView::initWithFrame(new NSRect(0.0, 0.0, 0.0, 0.0)));
        $scroll->setDocumentView($this->table);
        $scroll->setHasVerticalScroller(true);
    }

    public function native(): NSScrollView
    {
        return $this->native;
    }

    /** The table view, the scroll view's document: the first responder while the table has focus. */
    public function focusView(): NSTableView
    {
        return $this->table;
    }

    protected function applyRows(array $cells): void
    {
        $this->table->reloadData();
        $this->remeasure();
    }

    protected function applyBackground(?Color $color): void
    {
        $this->table->setBackgroundColor(is_null($color) ? $this->built_background : self::nsColor($color));
    }

    /**
     * The table view's own size, which it keeps at its columns and rows, plus the header above it.
     *
     * @param bool $horizontal
     * @return float
     */
    protected function naturalLength(bool $horizontal): float
    {
        $table = $this->table->frame();
        if ($horizontal) {
            return $table->width;
        }

        return $table->height + ($this->table->headerView()?->frame()->height ?? 0.0);
    }

    protected function applySelectedRow(?int $row): void
    {
        if (is_null($row)) {
            $this->table->deselectAll(null);

            return;
        }

        $this->table->selectRowIndexesByExtendingSelection(NSIndexSet::indexSetWithIndex($row), false);
    }

    protected function applyNativeEnabled(bool $on): void
    {
        $this->table->setEnabled($on);
    }

    protected function releaseNative(): void
    {
        $this->table->setDelegate(null);
        $this->table->setDataSource(null);
    }

    protected function selectionChanged(): void
    {
        $selected = $this->table->selectedRow();
        $row = $selected < 0 ? null : $selected;
        if ($row === $this->selected_row) {
            return;
        }

        $this->nativeRowSelected($row);
        $this->session()->post(new RowSelected(
            $this->window->name(), $this->path(), $this->uuid, $row, is_null($row) ? null : $this->rows[$row],
        ));
    }
}
