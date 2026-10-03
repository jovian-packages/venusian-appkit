<?php

namespace Jovian\Toolkits\Appkit\Primitives\Concerns;

use Jovian\Toolkits\Appkit\Bridge\AppkitSession;
use Jovian\Toolkits\Appkit\Primitives\AppkitFixed;
use Jovian\Toolkits\Appkit\Windows\AppkitWindow;
use NSColor;
use NSControl;
use NSFont;
use NSFontManager;
use NSLayoutConstraint;
use NSLayoutConstraintOrientation;
use NSNotificationCenter;
use NSObject;
use NSOperationQueue;
use NSRect;
use NSStackView;
use NSView;
use Surface\Contracts\Windows\Mail\View\ViewResized;
use Surface\Contracts\Windows\Primitives\Align;
use Surface\Contracts\Windows\Primitives\TKPrimitive;
use Surface\Contracts\Windows\Styling\FontSpec;
use Surface\Contracts\Windows\Styling\FontWeight;
use Surface\NutsAndBolts\Color;
use Surface\Windows\Primitives\TKPrimitiveGroup;

/**
 * The native side every AppKit primitive shares: the NSView it owns, the base apply* hooks,
 * size reads and watched-size mail. A concrete builds its view after parent::__construct()
 * and hands it to adoptNative(), which turns Auto Layout on for it.
 *
 * Three views, usually one: native() is the toolkit view the kind is; ownView() is what the
 * primitive contributes to layout (a grid's frame around its NSGridView); placedView() is what
 * its container arranges (a slot around ownView() while the child fills its column or row but
 * is aligned within that space, as Qt's alignment places a stretched widget).
 *
 * Layout follows GTK's model: on its container's main axis a child takes spare space only
 * when it, or anything visible inside it, fills, sharing it equally with the other fillers;
 * across the axis, and inside the space it takes, its Align places it (FILL stretches).
 * The container that holds a child owns the constraints that place it.
 */
trait AppkitPrimitive
{
    protected NSView $native;

    /**
     * The view the container arranges in place of ownView() while it fills its stack's main
     * axis with a main-axis Align other than FILL.
     * @var NSView|null
     */
    protected ?NSView $slot = null;

    /**
     * ownView() inside the slot: its main-axis place and its natural size.
     * @var list<NSLayoutConstraint>
     */
    protected array $slot_constraints = [];

    /**
     * The width/height floors minSize() set, on ownView().
     * @var list<NSLayoutConstraint>
     */
    protected array $min_size_constraints = [];

    /**
     * Token of the frame-change observer while the size is watched.
     * @var NSObject|null
     */
    protected ?NSObject $resize_observer = null;

    /**
     * Per axis (horizontal, vertical): the pull to its natural length that stands in for content
     * hugging on a placed view with no intrinsic size, while it does not fill.
     * @var array{0: NSLayoutConstraint|null, 1: NSLayoutConstraint|null}
     */
    protected array $hug_constraints = [null, null];

    /**
     * The size last posted as ViewResized; a frame change that keeps the size posts nothing.
     * @var array{int, int}|null
     */
    protected ?array $reported_size = null;

    public function native(): NSView
    {
        return $this->native;
    }

    /**
     * What this primitive contributes to layout: native() unless the kind wraps it.
     * @return NSView
     */
    public function ownView(): NSView
    {
        return $this->native;
    }

    /**
     * What the container arranges: the slot while there is one, else ownView().
     * @return NSView
     */
    public function placedView(): NSView
    {
        return $this->slot ?? $this->ownView();
    }

    /**
     * Whether this view takes spare space along an axis: its own fill, or any live visible
     * descendant's, as GTK computes expand. Driver containers read it.
     *
     * @param bool $horizontal
     * @return bool
     */
    public function nativeFills(bool $horizontal): bool
    {
        if ($horizontal ? $this->fill_horizontal : $this->fill_vertical) {
            return true;
        }
        if ($this instanceof TKPrimitiveGroup) {
            foreach ($this->children() as $child) {
                if (! $child->isRemoved() && $child->isVisible() && $child->nativeFills($horizontal)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Where this view sits on an axis inside the space its container gives it.
     *
     * @param bool $horizontal
     * @return Align
     */
    public function nativeAlign(bool $horizontal): Align
    {
        return $horizontal ? $this->align_horizontal : $this->align_vertical;
    }

    /**
     * Whether the placed view keeps to its natural size on one axis (hugs) or grows into spare
     * space. Content hugging only acts on an intrinsic size; a placed view without one on that
     * axis (a stack, grid, fixed or scroll view, a slot, an image) hugs through a low pull toward
     * its natural length, so what it holds sets its size.
     *
     * @param bool $horizontal
     * @param bool $hug
     * @return void
     */
    public function nativeHug(bool $horizontal, bool $hug): void
    {
        $axis = $horizontal ? 0 : 1;
        if (! is_null($this->hug_constraints[$axis])) {
            NSLayoutConstraint::deactivateConstraints([$this->hug_constraints[$axis]]);
        }
        $natural = is_null($this->slot) ? $this->naturalLength($horizontal) : 0.0;
        $this->hug_constraints[$axis] = self::hug($this->placedView(), $horizontal, $hug, $natural);
    }

    /**
     * Wrap ownView() in a slot that takes the space on the stack's main axis, with ownView()
     * placed inside it per $align at its natural length; or unwrap it (null). The container
     * swaps the placed view in its arrangement when placedView() changes.
     *
     * @param Align|null $align START, CENTER or END; null for no slot
     * @param bool $vertical the stack's main axis is vertical (a column)
     * @return void
     */
    public function nativeSlot(?Align $align, bool $vertical): void
    {
        NSLayoutConstraint::deactivateConstraints($this->slot_constraints);
        $this->slot_constraints = [];
        $own = $this->ownView();

        if (is_null($align)) {
            if (! is_null($this->slot)) {
                $own->removeFromSuperview();
                $this->slot->removeFromSuperview();
                $this->slot = null;
            }

            return;
        }

        if (is_null($this->slot)) {
            $this->slot = NSView::initWithFrame(new NSRect());
            $this->slot->setTranslatesAutoresizingMaskIntoConstraints(false);
            $own->removeFromSuperview();
            $this->slot->addSubview($own);
        }

        [$start, $end, $center, $across_start, $across_end] = $vertical
            ? ['topAnchor', 'bottomAnchor', 'centerYAnchor', 'leadingAnchor', 'trailingAnchor']
            : ['leadingAnchor', 'trailingAnchor', 'centerXAnchor', 'topAnchor', 'bottomAnchor'];
        $slot = $this->slot;
        $this->slot_constraints = [
            $own->$across_start()->constraintEqualToAnchor($slot->$across_start()),
            $own->$across_end()->constraintEqualToAnchor($slot->$across_end()),
            $own->$start()->constraintGreaterThanOrEqualToAnchor($slot->$start()),
            $own->$end()->constraintLessThanOrEqualToAnchor($slot->$end()),
            match ($align) {
                Align::START => $own->$start()->constraintEqualToAnchor($slot->$start()),
                Align::END => $own->$end()->constraintEqualToAnchor($slot->$end()),
                default => $own->$center()->constraintEqualToAnchor($slot->$center()),
            },
        ];
        NSLayoutConstraint::activateConstraints($this->slot_constraints);

        $natural = self::hug($own, ! $vertical, true, $this->naturalLength(! $vertical));
        if (! is_null($natural)) {
            $this->slot_constraints[] = $natural;
        }
    }

    /**
     * Write the enabled state the user sees: this view's own, unless a container above it is
     * disabled. Containers pass it down to every child; a child keeps its own setting.
     * @return void
     */
    public function syncNativeEnabled(): void
    {
        $on = $this->enabled;
        for ($ancestor = $this->parent; $on && ! is_null($ancestor); $ancestor = $ancestor->parent()) {
            $on = $ancestor->isEnabled();
        }
        $this->applyNativeEnabled($on);

        if ($this instanceof TKPrimitiveGroup) {
            foreach ($this->children() as $child) {
                if (! $child->isRemoved()) {
                    $child->syncNativeEnabled();
                }
            }
        }
    }

    /**
     * The length a view with no intrinsic size settles at when it hugs: zero, so what it holds
     * decides. A view that shows content of its own size (an image, a table's rows, a text
     * area's text) answers that size, read from the toolkit.
     *
     * @param bool $horizontal
     * @return float
     */
    protected function naturalLength(bool $horizontal): float
    {
        return 0.0;
    }

    /**
     * Placed already: its container measures it again (a natural length changed).
     * @return void
     */
    protected function remeasure(): void
    {
        if (! $this->removed && ! is_null($this->placedView()->superview())) {
            $this->parent?->placeChild($this);
        }
    }

    /**
     * @param NSView $native
     * @return NSView
     */
    protected function adoptNative(NSView $native): NSView
    {
        $native->setTranslatesAutoresizingMaskIntoConstraints(false);

        return $this->native = $native;
    }

    protected function host(): AppkitWindow
    {
        return $this->window;
    }

    protected function session(): AppkitSession
    {
        return $this->host()->session();
    }

    /**
     * A hidden view takes no space, and no longer makes its ancestors fill.
     *
     * @param bool $visible
     * @return void
     */
    protected function applyVisible(bool $visible): void
    {
        $this->placedView()->setHidden(! $visible);
        $this->replaceInAncestors();
    }

    protected function applyEnabled(bool $enabled): void
    {
        $this->syncNativeEnabled();
    }

    /**
     * The view whose enabled state is the user's: the control itself where the view is one.
     *
     * @param bool $on
     * @return void
     */
    protected function applyNativeEnabled(bool $on): void
    {
        if ($this->native instanceof NSControl) {
            $this->native->setEnabled($on);
        }
    }

    protected function applyBackground(?Color $color): void
    {
        $this->ownView()->setLayerBackgroundColor(is_null($color) ? null : self::nsColor($color));
    }

    /**
     * A fill change can change what every container above wants from this branch.
     *
     * @param bool $horizontal
     * @param bool $vertical
     * @return void
     */
    protected function applyFill(bool $horizontal, bool $vertical): void
    {
        $this->replaceInAncestors();
    }

    protected function applyAlign(Align $horizontal, Align $vertical): void
    {
        $this->parent?->placeChild($this);
    }

    /**
     * Width and height floors as required constraints on ownView(); a fixed's child takes them
     * through its frame.
     *
     * @param int $width
     * @param int $height
     * @return void
     */
    protected function applyMinSize(int $width, int $height): void
    {
        if ($this->parent instanceof AppkitFixed) {
            $this->parent->refitChild($this);

            return;
        }

        NSLayoutConstraint::deactivateConstraints($this->min_size_constraints);
        $own = $this->ownView();
        $this->min_size_constraints = [
            $own->widthAnchor()->constraintGreaterThanOrEqualToConstant((float) $width),
            $own->heightAnchor()->constraintGreaterThanOrEqualToConstant((float) $height),
        ];
        NSLayoutConstraint::activateConstraints($this->min_size_constraints);
    }

    /**
     * Watching posts ViewResized (latest per view) from the view's frame-change notification;
     * stopping drops the observer and any ViewResized not yet flushed.
     *
     * @param bool $on
     * @return void
     */
    protected function applyWatchSize(bool $on): void
    {
        if ($on) {
            $this->observeResize();

            return;
        }

        $this->stopObservingResize();
        $this->reported_size = null;
        $this->session()->forgetLatest($this->resizeKey());
    }

    protected function nativeSize(): array
    {
        $frame = $this->ownView()->frame();

        return [(int) round($frame->width), (int) round($frame->height)];
    }

    /**
     * Leave the container first, so it drops the constraints that placed this view, then the view tree.
     * @return void
     */
    protected function destroyNative(): void
    {
        $this->stopObservingResize();
        $this->parent?->removeNative($this);
        $this->releaseNative();
        $this->placedView()->removeFromSuperview();
        $this->ownView()->removeFromSuperview();
        $this->slot = null;
    }

    /**
     * A concrete's own native teardown: observers, targets, delegates.
     * @return void
     */
    protected function releaseNative(): void {}

    /**
     * Re-place this view in every container above it: a fill or visibility change here can
     * change whether each ancestor fills.
     * @return void
     */
    protected function replaceInAncestors(): void
    {
        for ($child = $this, $parent = $this->parent; ! is_null($parent); $child = $parent, $parent = $parent->parent()) {
            $parent->placeChild($child);
        }
    }

    protected function observeResize(): void
    {
        $own = $this->ownView();
        $own->setPostsFrameChangedNotifications(true);
        $this->resize_observer = NSNotificationCenter::defaultCenter()->addObserverForNameObjectQueueUsingBlock(
            NSViewFrameDidChangeNotification,
            $own,
            NSOperationQueue::mainQueue(),
            fn () => $this->postResized(),
        );
    }

    protected function stopObservingResize(): void
    {
        if (! is_null($this->resize_observer)) {
            NSNotificationCenter::defaultCenter()->removeObserver($this->resize_observer);
            $this->resize_observer = null;
        }
    }

    protected function postResized(): void
    {
        $size = $this->nativeSize();
        if ($this->removed || $size === $this->reported_size) {
            return;
        }

        $this->reported_size = $size;
        $this->session()->postLatest(
            $this->resizeKey(),
            new ViewResized($this->window->name(), $this->path(), $this->uuid, $size[0], $size[1]),
        );
    }

    protected function resizeKey(): string
    {
        return "view.resized.{$this->window->name()}.{$this->path()}";
    }

    /**
     * Hugging on $view: priority 750 (hug) or 1 (grow); when it hugs and has no intrinsic size
     * on that axis, the returned pull to $natural stands in for it, at 480: below the window's
     * size-stay-put (500), so a natural size never resizes a window, and below a wrapping
     * label's 490, so a container gives a wrapping label its full line when it has room.
     *
     * @param NSView $view
     * @param bool $horizontal
     * @param bool $hug
     * @param float $natural
     * @return NSLayoutConstraint|null
     */
    protected static function hug(NSView $view, bool $horizontal, bool $hug, float $natural): ?NSLayoutConstraint
    {
        $orientation = $horizontal ? NSLayoutConstraintOrientation::HORIZONTAL : NSLayoutConstraintOrientation::VERTICAL;
        $priority = $hug ? 750.0 : 1.0;
        $view->setContentHuggingPriorityForOrientation($priority, $orientation);
        if ($view instanceof NSStackView) {
            $view->setHuggingPriorityForOrientation($priority, $orientation);
        }

        $intrinsic = $view->intrinsicContentSize();
        if (! $hug || ($horizontal ? $intrinsic->width : $intrinsic->height) >= 0.0) {
            return null;
        }

        $pull = ($horizontal ? $view->widthAnchor() : $view->heightAnchor())->constraintEqualToConstant($natural);
        $pull->setPriority(480.0);
        NSLayoutConstraint::activateConstraints([$pull]);

        return $pull;
    }

    /**
     * @param Color $color
     * @return NSColor
     */
    protected static function nsColor(Color $color): NSColor
    {
        return NSColor::colorWithRedGreenBlueAlpha($color->red, $color->green, $color->blue, $color->alpha);
    }

    /**
     * The system font at the spec's size and weight, or the named family at that weight
     * through NSFontManager; a family that is not installed falls back to the system font,
     * as GTK's and Qt's font matching do.
     *
     * @param FontSpec $font
     * @return NSFont
     */
    protected static function nsFont(FontSpec $font): NSFont
    {
        if (! is_null($font->family)) {
            $named = NSFontManager::sharedFontManager()->fontWithFamilyTraitsWeightSize(
                $font->family, 0, self::managerWeight($font->weight), $font->size,
            );
            if (! is_null($named)) {
                return $named;
            }
        }

        return NSFont::systemFontOfSizeWeight($font->size, match ($font->weight) {
            FontWeight::LIGHT => NSFont::WEIGHT_LIGHT,
            FontWeight::REGULAR => NSFont::WEIGHT_REGULAR,
            FontWeight::MEDIUM => NSFont::WEIGHT_MEDIUM,
            FontWeight::SEMIBOLD => NSFont::WEIGHT_SEMIBOLD,
            FontWeight::BOLD => NSFont::WEIGHT_BOLD,
            FontWeight::BLACK => NSFont::WEIGHT_BLACK,
        });
    }

    /**
     * FontWeight on NSFontManager's 0–15 scale (5 regular, 9 bold).
     *
     * @param FontWeight $weight
     * @return int
     */
    protected static function managerWeight(FontWeight $weight): int
    {
        return match ($weight) {
            FontWeight::LIGHT => 3,
            FontWeight::REGULAR => 5,
            FontWeight::MEDIUM => 6,
            FontWeight::SEMIBOLD => 8,
            FontWeight::BOLD => 9,
            FontWeight::BLACK => 11,
        };
    }

    /**
     * The view a container arranges for $child.
     *
     * @param TKPrimitive $child
     * @return NSView
     */
    protected static function nativeOf(TKPrimitive $child): NSView
    {
        return $child->placedView();
    }
}
