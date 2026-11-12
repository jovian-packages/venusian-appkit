<?php

namespace Jovian\Toolkits\Appkit\Primitives;

use Jovian\Toolkits\Appkit\Primitives\Concerns\AppkitItemScroll;
use Jovian\Toolkits\Appkit\Primitives\Concerns\AppkitPrimitive;
use NSAnimationContext;
use NSButton;
use NSCollectionView;
use NSCollectionViewFlowLayout;
use NSCollectionViewItem;
use NSColor;
use NSImage;
use NSIndexPath;
use NSLineBreakMode;
use NSObject;
use NSRect;
use NSScrollView;
use NSSize;
use NSTextAlignment;
use NSTextField;
use NSTimer;
use NSView;
use ObjCDelegate;
use ObjCTarget;
use Surface\Contracts\Windows\Mail\View\ItemActionClicked;
use Surface\Contracts\Windows\Mail\View\ItemActivated;
use Surface\Contracts\Windows\Primitives\CollectionItem;
use Surface\Contracts\Windows\Primitives\Placement;
use Surface\Contracts\Windows\ToolkitWindow;
use Surface\Windows\Primitives\TKCollectionView;
use Surface\Windows\Primitives\TKPrimitiveGroup;
use WeakReference;

/**
 * An NSCollectionView with a flow layout in an NSScrollView (the native). The data source
 * answers from the items; each NSCollectionViewItem it makes gets one cell view, kept for the
 * item's reuse: a layer-backed image covering the cell above a one-line caption, and a
 * borderless image button in the top-right corner, the status icons on discs left of it. A click selects a cell; the selection posts
 * ItemActivated and is cleared, so the next click on the same cell posts again. Removals animate.
 */
class AppkitCollectionView extends TKCollectionView
{
    use AppkitPrimitive;
    use AppkitItemScroll;

    protected const string CELL = 'surface-cell';

    protected const float CAPTION = 20.0;

    protected const float ACTION = 28.0;

    /** A status icon's disc, in points. */
    protected const float DISC = 28.0;

    protected NSCollectionView $collection;

    protected NSCollectionViewFlowLayout $layout;

    /**
     * Collection views hold their data source and delegate weakly: the primitive keeps both.
     */
    protected ObjCDelegate $source;

    protected ObjCDelegate $delegate;

    /**
     * Each made item's parts, by the item's address; the targets live here too.
     * @var array<int, array{item: NSCollectionViewItem, image: NSView, caption: NSTextField, icons: NSView, action: NSButton, target: ObjCTarget}>
     */
    protected array $cells = [];

    /** The relayout asked for after new cells, while it waits for the next run-loop turn. */
    protected ?array $relayout = null;

    public function __construct(string $name, ToolkitWindow $window, ?TKPrimitiveGroup $parent, Placement $placement, array $items)
    {
        parent::__construct($name, $window, $parent, $placement, $items);

        $this->layout = NSCollectionViewFlowLayout::init();
        $this->layout->setItemSize(new NSSize($this->cell_width, $this->cell_height));
        $this->collection = NSCollectionView::initWithFrame(new NSRect(0.0, 0.0, 0.0, 0.0));
        $this->collection->setCollectionViewLayout($this->layout);
        $this->collection->registerClassForItemWithIdentifier('NSCollectionViewItem', self::CELL);
        $this->collection->setSelectable(true);

        // The data source and delegate hold their callables where PHP's collector cannot see them: a weak reference keeps them from holding the primitive.
        $self = WeakReference::create($this);
        $this->source = new ObjCDelegate('NSCollectionViewDataSource');
        $this->source->on('collectionView:numberOfItemsInSection:', static fn (NSObject $view, int $section): int => $self->get()?->count() ?? 0);
        $this->source->on('collectionView:itemForRepresentedObjectAtIndexPath:', static fn (NSCollectionView $view, NSIndexPath $path): ?NSCollectionViewItem => $self->get()?->cellAt($path));
        $this->collection->setDataSource($this->source);
        $this->delegate = new ObjCDelegate('NSCollectionViewDelegate');
        $this->delegate->on('collectionView:didSelectItemsAtIndexPaths:', static fn () => $self->get()?->selected());
        $this->collection->setDelegate($this->delegate);

        $scroll = $this->adoptNative(NSScrollView::initWithFrame(new NSRect(0.0, 0.0, 0.0, 0.0)));
        $scroll->setDocumentView($this->collection);
        $scroll->setHasVerticalScroller(true);
        $this->observeScroll($scroll, $this->collection);
    }

    public function native(): NSScrollView
    {
        return $this->native;
    }

    /** The collection view, the scroll view's document: the first responder while it has focus. */
    public function focusView(): NSCollectionView
    {
        return $this->collection;
    }

    protected function applyItems(array $items): void
    {
        $this->collection->reloadData();
    }

    protected function applyInserted(int $at, array $items): void
    {
        $this->collection->insertItemsAtIndexPaths(self::paths($at, count($items)));
    }

    protected function applyRemoved(int $at, int $count): void
    {
        NSAnimationContext::beginGrouping();
        NSAnimationContext::currentContext()->setDuration(0.3);
        $this->collection->animator()->deleteItemsAtIndexPaths(self::paths($at, $count));
        NSAnimationContext::endGrouping();
    }

    protected function applyUpdated(int $index, object $item): void
    {
        $this->collection->reloadItemsAtIndexPaths(self::paths($index, 1));
    }

    protected function applyCellSize(int $width, int $height): void
    {
        $this->layout->setItemSize(new NSSize($width, $height));
        foreach ($this->cells as $cell) {
            self::frameCell($cell, $width, $height);
        }
    }

    protected function applyNativeEnabled(bool $on): void
    {
        $this->collection->setSelectable($on);
    }

    protected function releaseNative(): void
    {
        if (! is_null($this->relayout)) {
            $this->relayout[0]->invalidate();
        }
        $this->relayout = null;
        $this->stopObservingScroll();
        $this->collection->setDelegate(null);
        $this->collection->setDataSource(null);
        foreach ($this->cells as $cell) {
            $cell['action']->setTarget(null);
        }
    }

    protected function cellAt(NSIndexPath $path): NSCollectionViewItem
    {
        $item = $this->collection->makeItemWithIdentifierForIndexPath(self::CELL, $path);
        $cell = $this->cells[$item->pointer()] ?? null;
        if (is_null($cell)) {
            $cell = $this->cells[$item->pointer()] = $this->buildCell($item);
            $this->relayoutSoon();
        }
        $this->bind($cell, $this->items[$path->item()] ?? new CollectionItem(null));

        return $item;
    }

    /**
     * A plain NSCollectionViewItem has no view while AppKit applies a new item's layout
     * attributes, so a cell view given to it stays at the origin. AppKit applies attributes
     * again only when they change: a reload on the next run-loop turn moves the items it gives
     * another index, but an item that gets its own index back keeps its view at the origin, over
     * the first cell (the APOD grid, shown after it loaded while hidden). So the turn also lays
     * the items out once at a cell one point taller and once at the cell size: every item's
     * attributes change, and AppKit places every view. Once the reuse pool covers a screen, no
     * new items are made and nothing follows.
     */
    protected function relayoutSoon(): void
    {
        if (! is_null($this->relayout)) {
            return;
        }
        $self = WeakReference::create($this);
        $target = new ObjCTarget(static function () use ($self): void {
            $view = $self->get();
            if (! is_null($view) && ! $view->removed) {
                $view->relayout = null;
                $view->collection->reloadData();
                $view->layout->setItemSize(new NSSize($view->cell_width, $view->cell_height + 1));
                $view->collection->layoutSubtreeIfNeeded();
                $view->layout->setItemSize(new NSSize($view->cell_width, $view->cell_height));
                $view->collection->layoutSubtreeIfNeeded();
            }
        });
        $this->relayout = [NSTimer::scheduledTimerWithTimeIntervalTargetSelectorUserInfoRepeats(0.0, $target, ObjCTarget::ACTION, null, false), $target];
    }

    /**
     * @return array{item: NSCollectionViewItem, image: NSView, caption: NSTextField, icons: NSView, action: NSButton, target: ObjCTarget}
     */
    protected function buildCell(NSCollectionViewItem $item): array
    {
        $view = NSView::initWithFrame(new NSRect(0.0, 0.0, $this->cell_width, $this->cell_height));
        $image = NSView::initWithFrame(new NSRect(0.0, 0.0, 0.0, 0.0));
        $image->setWantsLayer(true);
        $image->layer()?->setMasksToBounds(true);
        $image->layer()?->setCornerRadius(8.0);
        $image->layer()?->setContentsGravity(kCAGravityResizeAspectFill);
        $caption = NSTextField::labelWithString('');
        $caption->setAlignment(NSTextAlignment::CENTER);
        $caption->setLineBreakMode(NSLineBreakMode::TRUNCATING_TAIL);
        $key = $item->pointer();
        $self = WeakReference::create($this);
        $target = new ObjCTarget(static fn () => $self->get()?->actionClicked($key));
        $action = NSButton::buttonWithTitleTargetAction('', $target, ObjCTarget::ACTION);
        $action->setBordered(false);
        $icons = NSView::initWithFrame(new NSRect(0.0, 0.0, 0.0, 0.0));
        foreach ([$image, $caption, $icons, $action] as $part) {
            $view->addSubview($part);
        }
        $item->setView($view);
        $cell = ['item' => $item, 'image' => $image, 'caption' => $caption, 'icons' => $icons, 'action' => $action, 'target' => $target];
        self::frameCell($cell, $this->cell_width, $this->cell_height);

        return $cell;
    }

    /**
     * The parts inside the cell view; the layout places the cell view itself. AppKit's frames
     * start at the bottom left: the caption sits along the bottom edge.
     * @param array{item: NSCollectionViewItem, image: NSView, caption: NSTextField, icons: NSView, action: NSButton, target: ObjCTarget} $cell
     */
    protected static function frameCell(array $cell, int $width, int $height): void
    {
        $cell['image']->setFrame(new NSRect(0.0, self::CAPTION, $width, max(0.0, $height - self::CAPTION)));
        $cell['caption']->setFrame(new NSRect(0.0, 0.0, $width, self::CAPTION - 2.0));
        $cell['action']->setFrame(new NSRect($width - self::ACTION - 4.0, $height - self::ACTION - 4.0, self::ACTION, self::ACTION));
        // The status discs end at the action button when it shows, else at the corner.
        $discs = count($cell['icons']->subviews());
        $right = $cell['action']->isHidden() ? $width - 6.0 : $width - self::ACTION - 8.0;
        $w = max(0.0, $discs * (self::DISC + 4.0) - 4.0);
        $cell['icons']->setFrame(new NSRect($right - $w, $height - 6.0 - self::DISC, $w, self::DISC));
    }

    /**
     * One status icon: a white rim disc, a dark disc 1 point inside it, the icon centred in that.
     * Plain layer-backed views, so a click goes through to the cell.
     */
    protected static function disc(string $file, float $x): NSView
    {
        $layered = function (NSRect $frame, ?array $rgba, ?string $contents = null): NSView {
            $view = NSView::initWithFrame($frame);
            $view->setWantsLayer(true);
            $layer = $view->layer();
            is_null($rgba) || $layer?->setBackgroundColor(NSColor::colorWithRedGreenBlueAlpha(...$rgba)->CGColor());
            $layer?->setCornerRadius($frame->height / 2);
            if (! is_null($contents)) {
                $layer?->setContentsGravity(kCAGravityResizeAspect);
                $layer?->setContents(NSImage::initWithContentsOfFile($contents));
            }

            return $view;
        };
        $d = self::DISC;
        $rim = $layered(new NSRect($x, 0.0, $d, $d), [1.0, 1.0, 1.0, 0.7]);
        $rim->addSubview($layered(new NSRect(1.0, 1.0, $d - 2.0, $d - 2.0), [0.08, 0.08, 0.08, 0.85]));
        $rim->addSubview($layered(new NSRect(($d - 16.0) / 2, ($d - 16.0) / 2, 16.0, 16.0), null, $file));

        return $rim;
    }

    /**
     * @param array{item: NSCollectionViewItem, image: NSView, caption: NSTextField, icons: NSView, action: NSButton, target: ObjCTarget} $cell
     */
    protected function bind(array $cell, CollectionItem $item): void
    {
        $cell['image']->layer()?->setContents(is_null($item->image) ? null : NSImage::initWithContentsOfFile($item->image));
        $cell['caption']->setStringValue($item->caption);
        $cell['action']->setImage(is_null($item->action) ? null : NSImage::initWithContentsOfFile($item->action));
        $cell['action']->setHidden(is_null($item->action));
        foreach ($cell['icons']->subviews() as $old) {
            $old->removeFromSuperview();
        }
        foreach ($item->icons as $i => $file) {
            $cell['icons']->addSubview(self::disc($file, $i * (self::DISC + 4.0)));
        }
        self::frameCell($cell, $this->cell_width, $this->cell_height);
    }

    /**
     * @return array{item: NSCollectionViewItem, image: NSView, caption: NSTextField, icons: NSView, action: NSButton, target: ObjCTarget}|null
     */
    protected function cellsFor(NSCollectionViewItem $item): ?array
    {
        return $this->cells[$item->pointer()] ?? null;
    }

    protected function selected(): void
    {
        $paths = $this->collection->selectionIndexPaths();
        $this->collection->deselectItemsAtIndexPaths($paths);
        foreach ($paths as $path) {
            if ($this->enabled($path->item())) {
                $this->session()->post(new ItemActivated($this->window->name(), $this->path(), $this->uuid, $path->item()));
            }
        }
    }

    protected function actionClicked(int $key): void
    {
        $item = $this->cells[$key]['item'] ?? null;
        $index = is_null($item) ? null : $this->collection->indexPathForItem($item)?->item();
        if (! is_null($index) && $this->enabled($index)) {
            $this->session()->post(new ItemActionClicked($this->window->name(), $this->path(), $this->uuid, $index));
        }
    }

    /**
     * @return list<NSIndexPath>
     */
    protected static function paths(int $at, int $count): array
    {
        return array_map(fn (int $i): NSIndexPath => NSIndexPath::indexPathForItemInSection($i, 0), range($at, $at + $count - 1));
    }
}
