<?php

namespace Jovian\Toolkits\Appkit\UI;

use NSRect;
use NSView;
use NSRange;
use NSGridCell;
use NSGridView;
use NSLayoutConstraint;
use NSGridCellPlacement;
use NSLayoutConstraintOrientation;
use Surface\Toolkits\UI\TKGrid;
use Surface\Toolkits\UI\TKPrimitive;
use Surface\Contracts\Toolkits\UI\Align;
use Surface\Contracts\Toolkits\UI\Insets;
use Jovian\Toolkits\Appkit\UI\Concerns\AppkitNative;
use Jovian\Toolkits\Appkit\UI\Concerns\StretchesChildren;
use Jovian\Toolkits\Appkit\UI\Concerns\CarriesEnabledDown;

/**
 * An NSGridView inside a plain view. Merged cells cannot be unmerged, so the grid view is
 * built again whenever a child changes cells or leaves.
 *
 * The padding is the grid's pins inside the plain view, each side its own. A child's margin
 * is not shown: NSGridView's own space round a cell is a whole row's top and bottom padding
 * and a whole column's leading and trailing padding, which would move every cell in that line.
 */
class AppkitGrid extends TKGrid
{
    use AppkitNative {
        releaseNative as private releaseView;
    }
    use CarriesEnabledDown;
    use StretchesChildren;

    private ?NSGridView $grid = null;

    /** @var list<NSLayoutConstraint> */
    private array $pins = [];

    /** @var list<NSLayoutConstraint> */
    private array $ties = [];

    /** The whole grid is going: nothing is built again. */
    private bool $dismantling = false;

    protected function bootstrapNative(): void
    {
        $this->adoptNative(NSView::initWithFrame(new NSRect(0.0, 0.0, 0.0, 0.0)));
        $this->grid = $this->newGrid();
        $this->native()->addSubview($this->grid);
        $this->settle();
    }

    protected function attachNative(TKPrimitive $child): void
    {
        $this->put($child);
        $this->settle();
    }

    protected function detachNative(TKPrimitive $child): void
    {
        $child->native()->removeFromSuperview();
        if (! $this->dismantling) {
            $this->rebuild();
        }
    }

    protected function applyCells(): void
    {
        $this->rebuild();
    }

    protected function applySpacing(int $spacing): void
    {
        $this->grid->setRowSpacing((float) $spacing);
        $this->grid->setColumnSpacing((float) $spacing);
    }

    protected function applyPadding(Insets $padding): void
    {
        $this->settle();
    }

    protected function childLayoutChanged(TKPrimitive $child): void
    {
        $this->place($child);
        $this->settle();
    }

    protected function childVisibilityChanged(TKPrimitive $child): void
    {
        $this->settle();
    }

    protected function removeChildren(): void
    {
        $this->dismantling = true;
        parent::removeChildren();
    }

    protected function releaseNative(): void
    {
        NSLayoutConstraint::deactivateConstraints([...$this->pins, ...$this->ties]);
        $this->pins = [];
        $this->ties = [];
        $this->grid = null;
        $this->releaseView();
    }

    private function newGrid(): NSGridView
    {
        $grid = NSGridView::gridViewWithNumberOfColumnsRows(0, 0);
        $grid->setTranslatesAutoresizingMaskIntoConstraints(false);
        $grid->setRowSpacing((float) $this->spacing());
        $grid->setColumnSpacing((float) $this->spacing());

        return $grid;
    }

    private function rebuild(): void
    {
        NSLayoutConstraint::deactivateConstraints([...$this->pins, ...$this->ties]);
        $this->pins = [];
        $this->ties = [];

        foreach ($this->children() as $child) {
            $child->native()->removeFromSuperview();
        }
        $this->grid->removeFromSuperview();
        $this->grid = $this->newGrid();
        $this->native()->addSubview($this->grid);
        foreach ($this->children() as $child) {
            $this->put($child);
        }
        $this->settle();
    }

    private function put(TKPrimitive $child): void
    {
        $cell = $this->cellOf($child);
        $grid = $this->grid;
        while ($grid->numberOfColumns() < $cell->column + $cell->columnSpan) {
            $grid->addColumnWithViews(array_fill(0, $grid->numberOfRows(), NSGridCell::emptyContentView()));
        }
        while ($grid->numberOfRows() < $cell->row + $cell->rowSpan) {
            $grid->addRowWithViews(array_fill(0, $grid->numberOfColumns(), NSGridCell::emptyContentView()));
        }

        $grid->cellAtColumnIndexRowIndex($cell->column, $cell->row)->setContentView($child->native());
        if ($cell->rowSpan > 1 || $cell->columnSpan > 1) {
            $grid->mergeCellsInHorizontalRangeVerticalRange(new NSRange($cell->column, $cell->columnSpan), new NSRange($cell->row, $cell->rowSpan));
        }
        $this->place($child);
    }

    private function place(TKPrimitive $child): void
    {
        $at = $this->cellOf($child);
        $cell = $this->grid->cellAtColumnIndexRowIndex($at->column, $at->row);
        [$horizontal, $vertical] = $child->aligned();
        $cell->setXPlacement(self::cellPlacement($horizontal));
        $cell->setYPlacement(self::cellPlacement($vertical));
    }

    /**
     * How hard each child keeps its natural length. One that fills its cell gives way: at 750
     * a spanning button held every container above it to the button's width (observed). Where
     * its row or column expands it gives way first, so that line takes the room.
     */
    private function hug(): void
    {
        foreach ([[NSLayoutConstraintOrientation::HORIZONTAL, true], [NSLayoutConstraintOrientation::VERTICAL, false]] as [$orientation, $across]) {
            $spans = [];
            $expanding = [];
            foreach ($this->children() as $child) {
                $cell = $this->cellOf($child);
                [$from, $count] = $across ? [$cell->column, $cell->columnSpan] : [$cell->row, $cell->rowSpan];
                $lines = range($from, $from + $count - 1);
                $spans[] = [$child, $lines];
                if ($child->isVisible() && $child->expands($across)) {
                    $expanding += array_fill_keys($lines, true);
                }
            }

            foreach ($spans as [$child, $lines]) {
                $stretches = $child->aligned()[$across ? 0 : 1] === Align::FILL;
                $priority = match (true) {
                    $child->expands($across) => 1.0,
                    ! $stretches => 750.0,
                    array_intersect_key(array_flip($lines), $expanding) !== [] => 1.0,
                    default => 250.0,
                };
                $child->native()->setContentHuggingPriorityForOrientation($priority, $orientation);
            }
        }
    }

    /**
     * The padding round the outside, the grid pinned where a child expands and pulled to its
     * natural size where none does, and those that expand tied to one length.
     */
    private function settle(): void
    {
        NSLayoutConstraint::deactivateConstraints([...$this->pins, ...$this->ties]);
        $this->hug();

        $grid = $this->grid;
        $frame = $this->native();
        $padding = $this->padding();
        $this->pins = [
            $grid->leadingAnchor()->constraintEqualToAnchorConstant($frame->leadingAnchor(), (float) $padding->left),
            $grid->topAnchor()->constraintEqualToAnchorConstant($frame->topAnchor(), (float) $padding->top),
        ];
        $this->ties = [];

        foreach ([true, false] as $across) {
            $fillers = [];
            foreach ($this->children() as $child) {
                if ($child->isVisible() && $child->expands($across)) {
                    $fillers[] = $child->native();
                }
            }

            [$end, $room, $length, $inset] = $across
                ? [$grid->trailingAnchor(), $frame->trailingAnchor(), $grid->widthAnchor(), (float) $padding->right]
                : [$grid->bottomAnchor(), $frame->bottomAnchor(), $grid->heightAnchor(), (float) $padding->bottom];
            if ($fillers !== []) {
                $this->pins[] = $end->constraintEqualToAnchorConstant($room, -$inset);
            } else {
                $this->pins[] = $end->constraintLessThanOrEqualToAnchorConstant($room, -$inset);
                $natural = $length->constraintEqualToConstant(0.0);
                $natural->setPriority(480.0);
                $this->pins[] = $natural;
            }

            foreach (array_slice($fillers, 1) as $filler) {
                $tie = $across
                    ? $filler->widthAnchor()->constraintEqualToAnchor($fillers[0]->widthAnchor())
                    : $filler->heightAnchor()->constraintEqualToAnchor($fillers[0]->heightAnchor());
                $tie->setPriority(200.0);
                $this->ties[] = $tie;
            }
        }

        NSLayoutConstraint::activateConstraints([...$this->pins, ...$this->ties]);
        $this->tellExpansion();
    }

    private static function cellPlacement(Align $align): NSGridCellPlacement
    {
        return match ($align) {
            Align::START => NSGridCellPlacement::LEADING,
            Align::CENTER => NSGridCellPlacement::CENTER,
            Align::END => NSGridCellPlacement::TRAILING,
            Align::FILL => NSGridCellPlacement::FILL,
        };
    }
}
