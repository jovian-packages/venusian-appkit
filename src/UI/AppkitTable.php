<?php

namespace Jovian\Toolkits\Appkit\UI;

use NSRect;
use NSView;
use NSColor;
use NSObject;
use NSIndexSet;
use NSTableView;
use NSScrollView;
use ObjCDelegate;
use WeakReference;
use NSTableColumn;
use Surface\NutsAndBolts\Color;
use Surface\Toolkits\UI\TKTable;
use Jovian\Toolkits\Appkit\UI\Concerns\AppkitNative;
use Jovian\Toolkits\Appkit\UI\Concerns\ScrollsInside;
use Jovian\Toolkits\Appkit\UI\Concerns\TakesInputFromAbove;

/**
 * A cell-based NSTableView in a scroll view. It asks for the cells it shows; its delegate
 * reports the selection, whoever made it, and what code made is inside write().
 */
class AppkitTable extends TKTable
{
    use ScrollsInside, AppkitNative {
        ScrollsInside::hasFocus insteadof AppkitNative;
        ScrollsInside::nativeFocus insteadof AppkitNative;
        releaseNative as private releaseView;
    }
    use TakesInputFromAbove;

    private ?NSTableView $table = null;

    /** Tables hold their data source and delegate weakly: the primitive keeps both. */
    private ?ObjCDelegate $source = null;

    private ?ObjCDelegate $delegate = null;

    private ?NSColor $built_background = null;

    protected function bootstrapNative(): void
    {
        $table = NSTableView::initWithFrame(new NSRect(0.0, 0.0, 0.0, 0.0));
        foreach ($this->columns() as $column) {
            $native = NSTableColumn::initWithIdentifier($column->id);
            $native->setTitle($column->label);
            $table->addTableColumn($native);
        }

        $self = WeakReference::create($this);
        $this->source = new ObjCDelegate('NSTableViewDataSource');
        $this->source->on('numberOfRowsInTableView:', static fn (): int => count($self->get()?->rows ?? []));
        $this->source->on('tableView:objectValueForTableColumn:row:', static fn (NSObject $view, NSTableColumn $column, int $row): string => $self->get()?->rows[$row][$column->identifier()] ?? '');
        $table->setDataSource($this->source);

        $this->delegate = new ObjCDelegate('NSTableViewDelegate');
        $this->delegate->on('tableViewSelectionDidChange:', static function () use ($self): void {
            $self->get()?->selectionChanged();
        });
        $this->delegate->on('tableView:shouldEditTableColumn:row:', static fn (): int => 0);
        $table->setDelegate($this->delegate);
        $this->built_background = $table->backgroundColor();
        $this->table = $table;

        $scroll = NSScrollView::initWithFrame(new NSRect(0.0, 0.0, 0.0, 0.0));
        $scroll->setDocumentView($table);
        $scroll->setHasVerticalScroller(true);
        $scroll->setHasHorizontalScroller(true);
        $this->adoptNative($scroll);
        self::leastSize($scroll);
        $this->write(fn () => $table->reloadData());
    }

    protected function focusTarget(): NSView
    {
        return $this->table;
    }

    protected function applyRows(array $cells): void
    {
        $this->table->reloadData();
    }

    protected function applySelectedRow(?int $row): void
    {
        if (is_null($row)) {
            $this->table->deselectAll(null);

            return;
        }

        $this->table->selectRowIndexesByExtendingSelection(NSIndexSet::indexSetWithIndex($row), false);
    }

    protected function applyBackground(?Color $color): void
    {
        $this->table->setBackgroundColor(is_null($color) ? $this->built_background : self::nsColor($color));
    }

    protected function applyNativeEnabled(bool $takes_input): void
    {
        $this->table->setEnabled($takes_input);
    }

    protected function releaseNative(): void
    {
        $this->table->setDelegate(null);
        $this->table->setDataSource(null);
        $this->source = null;
        $this->delegate = null;
        $this->releaseView();
    }

    private function selectionChanged(): void
    {
        if (is_null($this->table)) {
            return;
        }
        $row = $this->table->selectedRow();
        $this->choseRow($row < 0 ? null : $row);
    }
}
