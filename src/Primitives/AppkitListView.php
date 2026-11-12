<?php

namespace Jovian\Toolkits\Appkit\Primitives;

use Jovian\Toolkits\Appkit\Primitives\Concerns\AppkitItemScroll;
use Jovian\Toolkits\Appkit\Primitives\Concerns\AppkitPrimitive;
use NSButton;
use NSColor;
use NSEvent;
use NSEventMask;
use NSFont;
use NSImage;
use NSImageScaling;
use NSImageView;
use NSIndexSet;
use NSLayoutConstraint;
use NSLayoutConstraintOrientation;
use NSLineBreakMode;
use NSObject;
use NSRect;
use NSScrollView;
use NSStackView;
use NSTableColumn;
use NSTableView;
use NSTextField;
use NSView;
use ObjCDelegate;
use ObjCTarget;
use Surface\Contracts\Windows\Mail\View\ItemActionClicked;
use Surface\Contracts\Windows\Mail\View\ItemActivated;
use Surface\Contracts\Windows\Primitives\ListRow;
use Surface\Contracts\Windows\Primitives\Placement;
use Surface\Contracts\Windows\ToolkitWindow;
use Surface\Windows\Primitives\TKListView;
use Surface\Windows\Primitives\TKPrimitiveGroup;
use WeakReference;

/**
 * A view-based NSTableView with one column and no header in an NSScrollView (the native). Rows
 * come from the delegate's tableView:viewForTableColumn:row:, reused by identifier; each row
 * view holds, laid out by constraints: a layer-backed image covering a 3:2 box at the leading
 * edge, the badge (an accent-filled label) over its top-left corner, the bold title line with
 * the status icons at its trailing end, up to three lines of small detail text, and the action button at
 * the trailing bottom corner. A disabled row is drawn at half opacity. The double action and
 * Return / Enter (while the table is first responder) post ItemActivated; the action button
 * posts ItemActionClicked. Inserts and removals animate.
 */
class AppkitListView extends TKListView
{
    use AppkitPrimitive;
    use AppkitItemScroll;

    protected const string ROW = 'surface-row';

    protected NSTableView $table;

    /**
     * Tables hold their data source, delegate and target weakly: the primitive keeps them.
     */
    protected ObjCDelegate $source;

    protected ObjCDelegate $delegate;

    protected ObjCTarget $target;

    protected ?NSObject $monitor = null;

    /**
     * Each row view's parts, by the view's address.
     * @var array<int, array{view: NSView, width: NSLayoutConstraint, image: NSView, badge: NSTextField, title: NSTextField, icons: NSStackView, detail: NSTextField, action: NSButton, target: ObjCTarget}>
     */
    protected array $rows = [];

    public function __construct(string $name, ToolkitWindow $window, ?TKPrimitiveGroup $parent, Placement $placement, array $items)
    {
        parent::__construct($name, $window, $parent, $placement, $items);

        $this->table = NSTableView::initWithFrame(new NSRect(0.0, 0.0, 0.0, 0.0));
        $this->table->addTableColumn(NSTableColumn::initWithIdentifier('row'));
        $this->table->setHeaderView(null);
        $this->table->setRowHeight($this->row_height);

        // The data source, delegate, target and monitor hold their callables where PHP's collector cannot see them: a weak reference keeps them from holding the primitive.
        $self = WeakReference::create($this);
        $this->source = new ObjCDelegate('NSTableViewDataSource');
        $this->source->on('numberOfRowsInTableView:', static fn (): int => $self->get()?->count() ?? 0);
        $this->table->setDataSource($this->source);
        $this->delegate = new ObjCDelegate('NSTableViewDelegate');
        $this->delegate->on('tableView:viewForTableColumn:row:', static fn (NSTableView $table, ?NSTableColumn $column, int $row): ?NSView => $self->get()?->rowView($row));
        $this->delegate->on('tableView:shouldSelectRow:', static fn (NSTableView $table, int $row): bool => $self->get()?->enabled($row) ?? false);
        $this->table->setDelegate($this->delegate);
        $this->target = new ObjCTarget(static fn () => $self->get()?->doubleClicked());
        $this->table->setTarget($this->target);
        $this->table->setDoubleAction(ObjCTarget::ACTION);
        $this->monitor = NSEvent::addLocalMonitorForEventsMatchingMaskHandler(
            NSEventMask::KEY_DOWN,
            static fn (NSEvent $event): ?NSEvent => is_null($list = $self->get()) ? $event : $list->keyDown($event),
        );

        $scroll = $this->adoptNative(NSScrollView::initWithFrame(new NSRect(0.0, 0.0, 0.0, 0.0)));
        $scroll->setDocumentView($this->table);
        $scroll->setHasVerticalScroller(true);
        $this->observeScroll($scroll, $this->table);
    }

    public function native(): NSScrollView
    {
        return $this->native;
    }

    /** The table view, the scroll view's document: the first responder while the list has focus. */
    public function focusView(): NSTableView
    {
        return $this->table;
    }

    protected function applyItems(array $items): void
    {
        $this->table->reloadData();
    }

    protected function applyInserted(int $at, array $items): void
    {
        foreach (array_keys($items) as $offset) {
            $this->table->insertRowsAtIndexesWithAnimation(NSIndexSet::indexSetWithIndex($at + $offset), NSTableView::ANIMATION_EFFECT_FADE);
        }
    }

    /** Each removal at $at shifts the next row up into it. */
    protected function applyRemoved(int $at, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->table->removeRowsAtIndexesWithAnimation(NSIndexSet::indexSetWithIndex($at), NSTableView::ANIMATION_EFFECT_FADE | NSTableView::ANIMATION_SLIDE_UP);
        }
    }

    protected function applyUpdated(int $index, object $item): void
    {
        $this->table->reloadDataForRowIndexesColumnIndexes(NSIndexSet::indexSetWithIndex($index), NSIndexSet::indexSetWithIndex(0));
    }

    protected function applyRowHeight(int $height): void
    {
        $this->table->setRowHeight($height);
        foreach ($this->rows as $row) {
            $row['width']->setConstant(self::imageWidth($height));
        }
    }

    /** The image box is 3:2 inside the row's 8-point margins. */
    protected static function imageWidth(int $rowHeight): float
    {
        return max(0.0, ($rowHeight - 16.0) * 1.5);
    }

    protected function applyNativeEnabled(bool $on): void
    {
        $this->table->setEnabled($on);
    }

    protected function releaseNative(): void
    {
        $this->stopObservingScroll();
        if (! is_null($this->monitor)) {
            NSEvent::removeMonitor($this->monitor);
            $this->monitor = null;
        }
        $this->table->setDoubleAction(null);
        $this->table->setTarget(null);
        $this->table->setDelegate(null);
        $this->table->setDataSource(null);
        foreach ($this->rows as $row) {
            $row['action']->setTarget(null);
        }
    }

    protected function rowView(int $index): NSView
    {
        $view = $this->table->makeViewWithIdentifierOwner(self::ROW, null);
        $row = is_null($view) ? $this->buildRow() : $this->rows[$view->pointer()];
        $this->bind($row, $this->items[$index] ?? new ListRow(null, ''));

        return $row['view'];
    }

    /**
     * @return array{view: NSView, width: NSLayoutConstraint, image: NSView, badge: NSTextField, title: NSTextField, icons: NSStackView, detail: NSTextField, action: NSButton, target: ObjCTarget}
     */
    protected function buildRow(): array
    {
        $view = NSView::initWithFrame(new NSRect(0.0, 0.0, 0.0, 0.0));
        $view->setIdentifier(self::ROW);

        $image = NSView::initWithFrame(new NSRect(0.0, 0.0, 0.0, 0.0));
        $image->setWantsLayer(true);
        $image->layer()?->setMasksToBounds(true);
        $image->layer()?->setCornerRadius(6.0);
        $image->layer()?->setContentsGravity(kCAGravityResizeAspectFill);

        $badge = NSTextField::labelWithString('');
        $badge->setFont(NSFont::systemFontOfSizeWeight(9.0, NSFont::WEIGHT_BOLD));
        $badge->setTextColor(NSColor::colorWithRedGreenBlueAlpha(1.0, 1.0, 1.0, 1.0));
        $badge->setDrawsBackground(true);
        $badge->setBackgroundColor(NSColor::controlAccentColor());

        $title = NSTextField::labelWithString('');
        $title->setFont(NSFont::systemFontOfSizeWeight(13.0, NSFont::WEIGHT_SEMIBOLD));
        $title->setLineBreakMode(NSLineBreakMode::TRUNCATING_TAIL);

        $icons = NSStackView::stackViewWithViews([]);
        $icons->setSpacing(4.0);

        // 0 = AppKit's automatic width: the detail wraps at the width the row gives it, up to three lines.
        $detail = NSTextField::labelWithString('');
        $detail->setFont(NSFont::systemFontOfSizeWeight(11.0, NSFont::WEIGHT_REGULAR));
        $detail->setLineBreakMode(NSLineBreakMode::WORD_WRAPPING);
        $detail->setMaximumNumberOfLines(3);
        $detail->setPreferredMaxLayoutWidth(0.0);
        $detail->setContentCompressionResistancePriorityForOrientation(250.0, NSLayoutConstraintOrientation::HORIZONTAL);
        $detail->setAlphaValue(0.65);

        $key = $view->pointer();
        $self = WeakReference::create($this);
        $target = new ObjCTarget(static fn () => $self->get()?->actionClicked($key));
        $action = NSButton::buttonWithTitleTargetAction('', $target, ObjCTarget::ACTION);

        foreach ([$image, $badge, $title, $icons, $detail, $action] as $part) {
            $part->setTranslatesAutoresizingMaskIntoConstraints(false);
            $view->addSubview($part);
        }
        $title->setContentCompressionResistancePriorityForOrientation(250.0, NSLayoutConstraintOrientation::HORIZONTAL);
        $width = $image->widthAnchor()->constraintEqualToConstant(self::imageWidth($this->row_height));
        NSLayoutConstraint::activateConstraints([
            $image->leadingAnchor()->constraintEqualToAnchorConstant($view->leadingAnchor(), 8.0),
            $image->topAnchor()->constraintEqualToAnchorConstant($view->topAnchor(), 8.0),
            $image->bottomAnchor()->constraintEqualToAnchorConstant($view->bottomAnchor(), -8.0),
            $width,
            $badge->leadingAnchor()->constraintEqualToAnchorConstant($image->leadingAnchor(), -2.0),
            $badge->topAnchor()->constraintEqualToAnchorConstant($image->topAnchor(), -2.0),
            $title->leadingAnchor()->constraintEqualToAnchorConstant($image->trailingAnchor(), 12.0),
            $title->topAnchor()->constraintEqualToAnchorConstant($view->topAnchor(), 8.0),
            // The icons at the line's trailing end, as GTK and Qt place them; a long title stops short of them.
            $icons->leadingAnchor()->constraintGreaterThanOrEqualToAnchorConstant($title->trailingAnchor(), 6.0),
            $icons->trailingAnchor()->constraintEqualToAnchorConstant($view->trailingAnchor(), -12.0),
            $icons->centerYAnchor()->constraintEqualToAnchor($title->centerYAnchor()),
            $detail->leadingAnchor()->constraintEqualToAnchor($title->leadingAnchor()),
            $detail->trailingAnchor()->constraintEqualToAnchorConstant($view->trailingAnchor(), -12.0),
            $detail->topAnchor()->constraintEqualToAnchorConstant($title->bottomAnchor(), 3.0),
            $detail->bottomAnchor()->constraintLessThanOrEqualToAnchorConstant($action->topAnchor(), -2.0),
            $action->trailingAnchor()->constraintEqualToAnchorConstant($view->trailingAnchor(), -12.0),
            $action->bottomAnchor()->constraintEqualToAnchorConstant($view->bottomAnchor(), -6.0),
        ]);

        return $this->rows[$key] = ['view' => $view, 'width' => $width, 'image' => $image, 'badge' => $badge, 'title' => $title, 'icons' => $icons, 'detail' => $detail, 'action' => $action, 'target' => $target];
    }

    /**
     * @param array{view: NSView, width: NSLayoutConstraint, image: NSView, badge: NSTextField, title: NSTextField, icons: NSStackView, detail: NSTextField, action: NSButton, target: ObjCTarget} $row
     */
    protected function bind(array $row, ListRow $item): void
    {
        $row['image']->layer()?->setContents(is_null($item->image) ? null : NSImage::initWithContentsOfFile($item->image));
        $row['badge']->setStringValue(is_null($item->badge) ? '' : " {$item->badge} ");
        $row['badge']->setHidden(is_null($item->badge));
        $row['title']->setStringValue($item->title);
        foreach ($row['icons']->arrangedSubviews() as $old) {
            $row['icons']->removeArrangedSubview($old);
            $old->removeFromSuperview();
        }
        foreach ($item->icons as $file) {
            $image = NSImage::initWithContentsOfFile($file);
            if (! is_null($image)) {
                $icon = NSImageView::imageViewWithImage($image);
                $icon->setImageScaling(NSImageScaling::PROPORTIONALLY_UP_OR_DOWN);
                $icon->widthAnchor()->constraintEqualToConstant(14.0)->setActive(true);
                $icon->heightAnchor()->constraintEqualToConstant(14.0)->setActive(true);
                $row['icons']->addArrangedSubview($icon);
            }
        }
        $row['detail']->setStringValue($item->detail);
        $row['action']->setTitle($item->action ?? '');
        $row['action']->setHidden(is_null($item->action));
        $row['action']->setEnabled($item->enabled);
        $row['view']->setAlphaValue($item->enabled ? 1.0 : 0.5);
    }

    /**
     * @return array{view: NSView, width: NSLayoutConstraint, image: NSView, badge: NSTextField, title: NSTextField, icons: NSStackView, detail: NSTextField, action: NSButton, target: ObjCTarget}|null
     */
    protected function rowsFor(NSView $view): ?array
    {
        return $this->rows[$view->pointer()] ?? null;
    }

    protected function doubleClicked(): void
    {
        $this->activate($this->table->clickedRow());
    }

    /**
     * Return or Enter in the table's window while the table is first responder activates the
     * selected row; the key goes no further. Any other key-down passes through.
     */
    protected function keyDown(NSEvent $event): ?NSEvent
    {
        $window = $this->table->window();
        if (is_null($window) || $event->window() !== $window || $window->firstResponder() !== $this->table) {
            return $event;
        }
        if (! in_array($event->characters(), [mb_chr(NSCarriageReturnCharacter), mb_chr(NSEnterCharacter)], true)) {
            return $event;
        }
        $row = $this->table->selectedRow();
        if ($row < 0) {
            return $event;
        }
        $this->activate($row);

        return null;
    }

    protected function activate(int $row): void
    {
        if ($this->enabled($row)) {
            $this->session()->post(new ItemActivated($this->window->name(), $this->path(), $this->uuid, $row));
        }
    }

    protected function actionClicked(int $key): void
    {
        $view = $this->rows[$key]['view'] ?? null;
        $row = is_null($view) ? -1 : $this->table->rowForView($view);
        if ($this->enabled($row)) {
            $this->session()->post(new ItemActionClicked($this->window->name(), $this->path(), $this->uuid, $row));
        }
    }
}
