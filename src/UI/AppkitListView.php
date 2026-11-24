<?php

namespace Jovian\Toolkits\Appkit\UI;

use NSFont;
use NSRect;
use NSView;
use NSColor;
use NSEvent;
use NSImage;
use NSButton;
use NSObject;
use NSIndexSet;
use ObjCTarget;
use NSEventMask;
use NSImageView;
use NSStackView;
use NSTableView;
use NSTextField;
use NSScrollView;
use ObjCDelegate;
use NSTableColumn;
use WeakReference;
use NSImageScaling;
use NSLineBreakMode;
use NSLayoutConstraint;
use NSLayoutConstraintOrientation;
use Surface\Toolkits\UI\TKListView;
use Surface\Contracts\Toolkits\UI\ListRow;
use Jovian\Toolkits\Appkit\UI\Concerns\AppkitNative;
use Jovian\Toolkits\Appkit\UI\Concerns\ScrollsInside;
use Jovian\Toolkits\Appkit\UI\Concerns\AppkitItemScroll;
use Jovian\Toolkits\Appkit\UI\Concerns\TakesInputFromAbove;

/**
 * A view-based NSTableView with no header: image and badge, title and icons, detail, action.
 */
class AppkitListView extends TKListView
{
    use ScrollsInside, AppkitNative {
        ScrollsInside::hasFocus insteadof AppkitNative;
        ScrollsInside::nativeFocus insteadof AppkitNative;
        releaseNative as private releaseView;
    }
    use TakesInputFromAbove;
    use AppkitItemScroll;

    protected const string ROW = 'surface-row';

    private ?NSTableView $table = null;

    /**
     * Tables hold their data source, delegate and target weakly: the primitive keeps them.
     */
    private ?ObjCDelegate $source = null;

    private ?ObjCDelegate $delegate = null;

    private ?ObjCTarget $target = null;

    protected ?NSObject $monitor = null;

    /**
     * Each row view's parts, by the view's address.
     * @var array<int, array{view: NSView, width: NSLayoutConstraint, image: NSView, badge: NSTextField, title: NSTextField, icons: NSStackView, detail: NSTextField, action: NSButton, target: ObjCTarget}>
     */
    protected array $rows = [];

    protected function bootstrapNative(): void
    {
        $table = NSTableView::initWithFrame(new NSRect(0.0, 0.0, 0.0, 0.0));
        $table->addTableColumn(NSTableColumn::initWithIdentifier('row'));
        $table->setHeaderView(null);
        $table->setRowHeight($this->row_height);
        $this->table = $table;

        $self = WeakReference::create($this);
        $this->source = new ObjCDelegate('NSTableViewDataSource');
        $this->source->on('numberOfRowsInTableView:', static fn (): int => $self->get()?->count() ?? 0);
        $table->setDataSource($this->source);
        $this->delegate = new ObjCDelegate('NSTableViewDelegate');
        $this->delegate->on('tableView:viewForTableColumn:row:', static fn (NSTableView $view, ?NSTableColumn $column, int $row): ?NSView => $self->get()?->rowView($row));
        $this->delegate->on('tableView:shouldSelectRow:', static fn (NSTableView $view, int $row): bool => $self->get()?->itemEnabled($row) ?? false);
        $table->setDelegate($this->delegate);
        $this->target = new ObjCTarget(static function () use ($self): void {
            $self->get()?->doubleClicked();
        });
        $table->setTarget($this->target);
        $table->setDoubleAction(ObjCTarget::ACTION);
        $this->monitor = NSEvent::addLocalMonitorForEventsMatchingMaskHandler(
            NSEventMask::KEY_DOWN,
            static fn (NSEvent $event): ?NSEvent => is_null($list = $self->get()) ? $event : $list->keyDown($event),
        );

        $scroll = NSScrollView::initWithFrame(new NSRect(0.0, 0.0, 0.0, 0.0));
        $scroll->setDocumentView($table);
        $scroll->setHasVerticalScroller(true);
        $this->adoptNative($scroll);
        self::leastSize($scroll);
        $this->observeScroll($scroll, $table);
        $this->write(fn () => $table->reloadData());
    }

    protected function focusTarget(): NSView
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

    protected function applyNativeEnabled(bool $takes_input): void
    {
        $this->table->setEnabled($takes_input);
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
        $this->rows = [];
        $this->releaseView();
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
        $target = new ObjCTarget(static function () use ($self, $key): void {
            $self->get()?->clickedActionIn($key);
        });
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

    protected function doubleClicked(): void
    {
        $this->activated($this->table->clickedRow());
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
        $this->activated($row);

        return null;
    }

    protected function clickedActionIn(int $key): void
    {
        $view = $this->rows[$key]['view'] ?? null;
        if (! is_null($view)) {
            $this->actionClicked($this->table->rowForView($view));
        }
    }
}
