<?php

namespace Jovian\Toolkits\Appkit\Primitives;

use Jovian\Toolkits\Appkit\Primitives\Concerns\AppkitPrimitive;
use NSGridCellPlacement;
use NSGridView;
use NSLayoutConstraint;
use NSRange;
use NSRect;
use NSView;
use Surface\Contracts\Windows\Primitives\Align;
use Surface\Contracts\Windows\Primitives\Placement;
use Surface\Contracts\Windows\Primitives\TKPrimitive as Child;
use Surface\Contracts\Windows\ToolkitWindow;
use Surface\Windows\Primitives\TKGrid;
use Surface\Windows\Primitives\TKPrimitiveGroup;

/**
 * An NSGridView (native()) inside a plain frame view (ownView()), grown row by row and column
 * by column as children ask for cells. The grid is pinned to the frame's top-left; on an axis
 * where no child fills it keeps its natural size and leaves the rest of the frame empty, as
 * GtkGrid and Qt's spare line do, so no hugging inside it reaches the window. On an axis where
 * children fill, the grid spans the frame and the filling children share the spare space.
 *
 * Spans are merged cells. AppKit cannot unmerge, so when a spanning child goes the NSGridView is
 * rebuilt inside the frame from the children that stay: a later child then takes only the cells
 * it names. Padding is the outer padding of the first and last row and column.
 */
class AppkitGrid extends TKGrid
{
    use AppkitPrimitive;

    protected NSView $frame;

    /**
     * The grid's edges against the frame.
     * @var list<NSLayoutConstraint>
     */
    protected array $pins = [];

    /**
     * Equal-length ties between the children that fill, per axis.
     * @var list<NSLayoutConstraint>
     */
    protected array $ties = [];

    /**
     * Set while the grid removes its own children on its way out: nothing is rebuilt then.
     */
    protected bool $dismantling = false;

    public function __construct(string $name, ToolkitWindow $window, ?TKPrimitiveGroup $parent, Placement $placement, int $spacing, int $padding)
    {
        parent::__construct($name, $window, $parent, $placement, $spacing, $padding);
        $this->frame = NSView::initWithFrame(new NSRect());
        $this->frame->setTranslatesAutoresizingMaskIntoConstraints(false);
        $this->frame->addSubview($this->adoptNative($this->buildGrid()));
        $this->pin();
    }

    public function native(): NSGridView
    {
        return $this->native;
    }

    public function ownView(): NSView
    {
        return $this->frame;
    }

    public function remove(): void
    {
        $this->dismantling = true;
        parent::remove();
    }

    protected function insertNative(Child $child): void
    {
        $this->attach($child);
        $this->pin();
        $this->tie();
        $child->syncNativeEnabled();
        $this->replaceInAncestors();
    }

    protected function applySpacing(int $spacing): void
    {
        $this->native->setRowSpacing((float) $spacing);
        $this->native->setColumnSpacing((float) $spacing);
    }

    /**
     * Cell placement per Align on both axes; a child that fills hugs low, so its row or column
     * takes the spare space, shared with the other fillers.
     *
     * @param Child $child
     * @return void
     */
    public function placeChild(Child $child): void
    {
        $placement = $child->placement();
        $cell = $this->native->cellAtColumnIndexRowIndex($placement->column, $placement->row);
        $cell->setXPlacement(self::cellPlacement($child->nativeAlign(true)));
        $cell->setYPlacement(self::cellPlacement($child->nativeAlign(false)));
        $child->nativeHug(true, ! $child->nativeFills(true));
        $child->nativeHug(false, ! $child->nativeFills(false));
        $this->pin();
        $this->tie();
    }

    /**
     * @param Child $child
     * @return void
     */
    public function removeNative(Child $child): void
    {
        $placement = $child->placement();
        $this->native->cellAtColumnIndexRowIndex($placement->column, $placement->row)->setContentView(null);

        if ($this->dismantling) {
            return;
        }
        if ($placement->rowSpan > 1 || $placement->columnSpan > 1) {
            $this->rebuild();
        }
        $this->pin();
        $this->tie();
        $this->replaceInAncestors();
    }

    protected function buildGrid(): NSGridView
    {
        $grid = NSGridView::gridViewWithNumberOfColumnsRows(0, 0);
        $grid->setRowSpacing((float) $this->spacing);
        $grid->setColumnSpacing((float) $this->spacing);

        return $grid;
    }

    /**
     * Grow to the child's cells, put it in its head cell, merge its span, place it.
     *
     * @param Child $child
     * @return void
     */
    protected function attach(Child $child): void
    {
        $placement = $child->placement();
        $this->grow($placement->row + $placement->rowSpan, $placement->column + $placement->columnSpan);
        $this->native->cellAtColumnIndexRowIndex($placement->column, $placement->row)->setContentView(self::nativeOf($child));
        if ($placement->rowSpan > 1 || $placement->columnSpan > 1) {
            $this->native->mergeCellsInHorizontalRangeVerticalRange(
                new NSRange($placement->column, $placement->columnSpan),
                new NSRange($placement->row, $placement->rowSpan),
            );
        }
        $this->placeChild($child);
    }

    protected function grow(int $rows, int $columns): void
    {
        $grid = $this->native;
        while ($grid->numberOfColumns() < $columns) {
            $grid->addColumnWithViews(array_fill(0, $grid->numberOfRows(), null));
        }
        while ($grid->numberOfRows() < $rows) {
            $grid->addRowWithViews(array_fill(0, $grid->numberOfColumns(), null));
        }
        $this->pad();
    }

    /**
     * The padding as the outer padding of the first and last row and column; inner edges get none.
     * @return void
     */
    protected function pad(): void
    {
        $padding = (float) $this->padding;
        $rows = $this->native->numberOfRows();
        for ($i = 0; $i < $rows; $i++) {
            $row = $this->native->rowAtIndex($i);
            $row->setTopPadding($i === 0 ? $padding : 0.0);
            $row->setBottomPadding($i === $rows - 1 ? $padding : 0.0);
        }
        $columns = $this->native->numberOfColumns();
        for ($i = 0; $i < $columns; $i++) {
            $column = $this->native->columnAtIndex($i);
            $column->setLeadingPadding($i === 0 ? $padding : 0.0);
            $column->setTrailingPadding($i === $columns - 1 ? $padding : 0.0);
        }
    }

    /**
     * Top-left always; trailing and bottom equal to the frame on an axis a child fills, else
     * within it with the grid pulled to its natural size.
     * @return void
     */
    protected function pin(): void
    {
        NSLayoutConstraint::deactivateConstraints($this->pins);
        $grid = $this->native;
        $frame = $this->frame;
        $across = $this->filling(true) !== [];
        $down = $this->filling(false) !== [];

        $this->pins = [
            $grid->leadingAnchor()->constraintEqualToAnchor($frame->leadingAnchor()),
            $grid->topAnchor()->constraintEqualToAnchor($frame->topAnchor()),
            $across ? $grid->trailingAnchor()->constraintEqualToAnchor($frame->trailingAnchor()) : $grid->trailingAnchor()->constraintLessThanOrEqualToAnchor($frame->trailingAnchor()),
            $down ? $grid->bottomAnchor()->constraintEqualToAnchor($frame->bottomAnchor()) : $grid->bottomAnchor()->constraintLessThanOrEqualToAnchor($frame->bottomAnchor()),
        ];
        foreach ([[$across, $grid->widthAnchor()], [$down, $grid->heightAnchor()]] as [$fills, $length]) {
            if (! $fills) {
                $natural = $length->constraintEqualToConstant(0.0);
                $natural->setPriority(480.0);
                $this->pins[] = $natural;
            }
        }
        NSLayoutConstraint::activateConstraints($this->pins);
    }

    /**
     * Per axis, every filling child takes the same length as the first filler.
     * @return void
     */
    protected function tie(): void
    {
        NSLayoutConstraint::deactivateConstraints($this->ties);
        $this->ties = [];
        foreach ([true => 'widthAnchor', false => 'heightAnchor'] as $horizontal => $length) {
            $fillers = $this->filling((bool) $horizontal);
            foreach (array_slice($fillers, 1) as $filler) {
                $tie = self::nativeOf($filler)->$length()->constraintEqualToAnchor(self::nativeOf($fillers[0])->$length());
                $tie->setPriority(200.0);
                $this->ties[] = $tie;
            }
        }
        NSLayoutConstraint::activateConstraints($this->ties);
    }

    /**
     * @param bool $horizontal
     * @return list<Child> live, visible children that fill the axis
     */
    protected function filling(bool $horizontal): array
    {
        return array_values(array_filter(
            $this->children,
            fn (Child $child): bool => ! $child->isRemoved() && $child->isVisible() && $child->nativeFills($horizontal),
        ));
    }

    /**
     * A fresh NSGridView inside the frame, holding the live children. The child on its way out is
     * already marked removed, so it is left behind. The frame, and with it the grid's place in
     * its container, its minimum size and its size watching, stays.
     * @return void
     */
    protected function rebuild(): void
    {
        $previous = $this->native;
        $live = array_filter($this->children, fn (Child $child): bool => ! $child->isRemoved());
        foreach ($live as $child) {
            $placement = $child->placement();
            $previous->cellAtColumnIndexRowIndex($placement->column, $placement->row)->setContentView(null);
        }
        NSLayoutConstraint::deactivateConstraints($this->pins);
        $this->pins = [];
        $previous->removeFromSuperview();

        $this->frame->addSubview($this->adoptNative($this->buildGrid()));
        foreach ($live as $child) {
            $this->attach($child);
        }
    }

    /**
     * @param Align $align
     * @return NSGridCellPlacement LEADING/TRAILING are top/bottom on the vertical axis
     */
    protected static function cellPlacement(Align $align): NSGridCellPlacement
    {
        return match ($align) {
            Align::START => NSGridCellPlacement::LEADING,
            Align::CENTER => NSGridCellPlacement::CENTER,
            Align::END => NSGridCellPlacement::TRAILING,
            Align::FILL => NSGridCellPlacement::FILL,
        };
    }
}
