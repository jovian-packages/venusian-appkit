<?php

namespace Jovian\Venusian\AppKit\Views;

use AppKit\Bridge\Bridge as ExtBridge;
use AppKit\NS\NSTableColumn\NSTableColumn as ExtNSTableColumn;
use Jovian\Bindings\AppKit\NS\NSIndexSet;
use Jovian\Bindings\AppKit\NS\NSScrollView;
use Jovian\Bindings\AppKit\NS\NSTableColumn;
use Jovian\Bindings\AppKit\NS\NSTableView;
use Jovian\Bindings\AppKit\NS\NSTextField;
use Jovian\Bindings\AppKit\Runtime\Delegate;
use Jovian\Bindings\AppKit\Values\NSRect;
use Surface\Contracts\NativeWindows\Views\Color;
use Surface\NativeWindows\Views\Table;
use Surface\NativeWindows\Windowable;

/**
 * A Surface table over NSTableView hosted in an NSScrollView. The scroll
 * view is the framed node. Columns are NSTableColumn headers; cells are
 * NSTextField labels from tableView:viewForTableColumn:row:. Selection
 * is single-row; selectRowIndexes:byExtendingSelection: and reloadData
 * both fire tableViewSelectionDidChange:, so applying keeps Surface's
 * own setters silent.
 *
 * NSTableColumn::initWithIdentifier is string on the ext and int on the
 * generated DTO (NSUserInterfaceItemIdentifier typedef). The twin calls
 * the ext and boxes — never chain ->handle off a temp.
 */
class AppKitTable extends Table
{
    protected bool $applying = false;

    /** Held for the life of the table — PHP refcount owns the native delegates. */
    protected Delegate $data_source;

    protected Delegate $table_delegate;

    /** @var list<NSTableColumn> */
    protected array $column_widgets = [];

    /** @var list<NSTextField> */
    protected array $cell_views = [];

    /**
     * @param list<string> $columns
     * @param list<list<string>> $rows
     */
    public function __construct(
        string $name,
        Windowable $window,
        array $columns,
        array $rows,
        public readonly NSScrollView $scroll,
        public readonly NSTableView $table,
    ) {
        parent::__construct($name, $window, $columns, $rows);

        $this->table->setAllowsEmptySelection(true);
        $this->installColumns($this->columns);

        $this->data_source = new Delegate('NSTableViewDataSource');
        $this->data_source->on('numberOfRowsInTableView:', function (mixed ...$args): int {
            return count($this->rows);
        });
        $this->table->setDataSource($this->data_source->handle());

        $this->table_delegate = new Delegate('NSTableViewDelegate');
        // Row is an index, not a handle — the boxed Bridge would treat 1 as
        // a live object. Talk to the ext so the ints stay ints.
        ExtBridge::delegateOn(
            $this->table_delegate->handle(),
            'tableView:viewForTableColumn:row:',
            function (int $table, int $column, int $row): int {
                $col = $this->columnIndex($column);
                $text = $this->rows[$row][$col] ?? '';
                $field = NSTextField::labelWithString($text);
                if (! $field instanceof NSTextField) {
                    return 0;
                }
                $this->cell_views[] = $field;

                return $field->handle;
            },
        );
        $this->table_delegate->on('tableViewSelectionDidChange:', function (mixed ...$args): void {
            if (! $this->applying) {
                $this->fireSelected($this->table->selectedRow());
            }
        });
        $this->table->setDelegate($this->table_delegate->handle());

        $this->applying = true;
        $this->table->reloadData();
        $this->applying = false;
    }

    protected function applyColumns(array $columns): void
    {
        $this->applying = true;
        foreach ($this->column_widgets as $column) {
            $this->table->removeTableColumn($column->handle);
        }
        $this->column_widgets = [];
        $this->installColumns($columns);
        $this->table->reloadData();
        $this->applying = false;
    }

    protected function applyRows(array $rows): void
    {
        $this->applying = true;
        $previous = $this->cell_views;
        $this->cell_views = [];
        $this->table->reloadData();
        unset($previous);
        $this->applying = false;
    }

    protected function applySelectedRow(int $selected): void
    {
        $this->applying = true;
        if ($selected < 0) {
            $this->table->deselectAll(0);
            $this->applying = false;

            return;
        }

        $set = NSIndexSet::indexSetWithIndex($selected);
        if ($set instanceof NSIndexSet) {
            $this->table->selectRowIndexesByExtendingSelection($set->handle, false);
        }
        $this->applying = false;
    }

    protected function applyEnabled(bool $enabled): void
    {
        $this->table->setEnabled($enabled);
    }

    protected function applyFrame(int $x, int $y, int $width, int $height): void
    {
        [, $content_height] = $this->layoutSpace();

        $this->scroll->setFrame(new NSRect(
            (float) $x,
            (float) ($content_height - $y - $height),
            (float) $width,
            (float) $height,
        ));
    }

    protected function measure(): array
    {
        return [$this->width, $this->height];
    }

    protected function destroyNative(): void
    {
        $this->scroll->removeFromSuperview();
    }

    protected function applyVisible(bool $visible): void
    {
        $this->scroll->setHidden(! $visible);
    }

    protected function applyBackground(Color $color): void {}

    /**
     * @param list<string> $columns
     */
    protected function installColumns(array $columns): void
    {
        foreach (array_values($columns) as $index => $title) {
            $ident = 'col-' . $index;
            $column = NSTableColumn::box(ExtNSTableColumn::initWithIdentifier($ident));
            if (! $column instanceof NSTableColumn) {
                continue;
            }
            $column->setTitle($title);
            $this->table->addTableColumn($column->handle);
            $this->column_widgets[] = $column;
        }
    }

    protected function columnIndex(int $columnHandle): int
    {
        foreach ($this->column_widgets as $index => $column) {
            if ($column->handle === $columnHandle) {
                return $index;
            }
        }

        return -1;
    }
}
