<?php

namespace Jovian\Toolkits\Appkit\Windows;

use CADisplayLink;
use CFData;
use CGColorSpace;
use CGDataProvider;
use CGDisplay;
use CGImage;
use CGInterpolationQuality;
use IOPMAssertion;
use Jovian\Toolkits\Appkit\Bridge\AppkitBridgeDriver;
use Jovian\Toolkits\Appkit\Bridge\AppkitSession;
use Jovian\Toolkits\Appkit\Windows\Concerns\LendsAppkitSurfaces;
use NSBackingStoreType;
use NSColor;
use NSGraphicsContext;
use NSImage;
use NSPoint;
use NSRect;
use NSRequestUserAttentionType;
use NSRunLoop;
use NSSize;
use NSView;
use NSViewLayerContentsRedrawPolicy;
use NSWindow;
use NSWindowCollectionBehavior;
use NSWindowStyleMask;
use ObjCDelegate;
use ObjCDrawView;
use ObjCStageWindow;
use ObjCTarget;
use Surface\Contracts\Drawing\VSync;
use Surface\Contracts\Framebuffers\Region;
use Surface\Contracts\Windows\Display;
use Surface\Contracts\Windows\DisplayMode;
use Surface\Contracts\Windows\Hdr;
use Surface\Contracts\Windows\ScaleFilter;
use Surface\Contracts\Windows\WindowCapability;
use Surface\Contracts\Windows\WindowException;
use Surface\Contracts\Windows\WindowMode;
use Surface\Windows\StagedWindow;

/**
 * A staged window on AppKit: an ObjCStageWindow (so a borderless one takes
 * focus, and focusable: false refuses it) whose content view is an
 * ObjCDrawView. present() copies the frame into a CGImage and marks only the
 * damaged rects for display; drawRect: draws the image into presentRect()
 * with the scaling filter, clipped to the dirty rect. With vsync On AppKit
 * draws at its next display cycle; otherwise present() draws at once.
 *
 * Fullscreen is native full screen (a Space of its own, animated); Exclusive
 * captures the display, switches its mode and covers it with the window at
 * the shielding level. A mode asked for while a full-screen transition runs,
 * or before the window is shown, waits and is applied after.
 */
class AppkitStagedWindow extends StagedWindow
{
    use LendsAppkitSurfaces;

    protected static ?CGColorSpace $space = null;

    protected ?ObjCStageWindow $window;

    protected ObjCDrawView $view;

    /** Hears the window's notifications. AppKit holds delegates weakly: kept here. */
    protected ObjCDelegate $delegate;

    protected ?CADisplayLink $link = null;

    protected ?ObjCTarget $link_target = null;

    protected bool $key = false;

    /** The image drawRect: draws, the last frame presented. */
    protected ?CGImage $image = null;

    /** @var array{int, int} the presented frame's size in pixels */
    protected array $image_size = [0, 0];

    /** drawRect: calls, for tests. */
    public int $draws = 0;

    /** @var list<NSRect> the dirty rects drawRect: was asked for, for tests */
    public array $dirty = [];

    /** The mode last reported through modeChanged(). */
    protected WindowMode $native_mode = WindowMode::Windowed;

    /** A native full-screen transition runs until AppKit says it entered or left. */
    protected bool $transitioning = false;

    /** @var array{WindowMode, Display|DisplayMode|null}|null a mode waiting for the window to show or a transition to end */
    protected ?array $pending = null;

    /** The display held in exclusive fullscreen. */
    protected ?int $captured = null;

    /** @var array{NSRect, int}|null the windowed frame and style mask exclusive fullscreen replaced */
    protected ?array $before_exclusive = null;

    protected ?int $assertion = null;

    protected float $last_scale;

    protected bool $clamping = false;

    public function __construct(
        string $name,
        AppkitSession $session,
        protected readonly AppkitBridgeDriver $driver,
        int $width,
        int $height,
        array $options,
    ) {
        parent::__construct($name, $session, $options);

        $this->window = ObjCStageWindow::initWithContentRectStyleMaskBackingDefer(
            new NSRect(0.0, 0.0, (float) $width, (float) $height), $this->styleMask(), NSBackingStoreType::BUFFERED, false,
        );
        // The PHP object holds the window; AppKit must not release it a second time on close.
        $this->window->setReleasedWhenClosed(false);
        $this->window->setCanBecomeKey($options['focusable']);
        $this->window->setCollectionBehavior(NSWindowCollectionBehavior::FULL_SCREEN_PRIMARY);
        $this->window->setLevel($options['always_on_top'] ? NSFloatingWindowLevel : NSNormalWindowLevel);
        if ($options['transparent']) {
            $this->window->setOpaque(false);
            $this->window->setBackgroundColor(NSColor::colorWithRedGreenBlueAlpha(0.0, 0.0, 0.0, 0.0));
        }

        $this->view = ObjCDrawView::initWithFrameDraw(new NSRect(0.0, 0.0, (float) $width, (float) $height), fn (ObjCDrawView $view, NSRect $dirty) => $this->draw($dirty));
        $this->restoreOwnLayer();
        $this->window->setContentView($this->view);

        $this->delegate = new ObjCDelegate('NSWindowDelegate');
        $this->delegate->on('windowDidBecomeKey:', fn () => $this->becameKey());
        $this->delegate->on('windowDidResignKey:', fn () => $this->resignedKey());
        $this->delegate->on('windowShouldClose:', fn (): bool => $this->shouldClose());
        $this->delegate->on('windowWillClose:', fn () => $this->willClose());
        $this->delegate->on('windowDidResize:', fn () => $this->didResize());
        $this->delegate->on('windowDidMove:', fn () => $this->moved(...$this->nativePosition()));
        $this->delegate->on('windowDidMiniaturize:', fn () => $this->syncMode());
        $this->delegate->on('windowDidDeminiaturize:', fn () => $this->syncMode());
        $this->delegate->on('windowDidEnterFullScreen:', fn () => $this->transitionEnded());
        $this->delegate->on('windowDidExitFullScreen:', fn () => $this->transitionEnded());
        $this->delegate->on('windowDidFailToEnterFullScreen:', fn () => $this->transitionEnded());
        $this->delegate->on('windowDidFailToExitFullScreen:', fn () => $this->transitionEnded());
        $this->delegate->on('windowDidChangeScreen:', fn () => $this->displayChanged($this->nativeDisplay()->id));
        $this->delegate->on('windowDidChangeBackingProperties:', fn () => $this->backingChanged());
        $this->delegate->on('windowDidChangeOcclusionState:', fn () => ($this->window->occlusionState() & 2) !== 0 ? $this->exposed() : $this->occluded());
        $this->window->setDelegate($this->delegate);
        $this->last_scale = $this->window->backingScaleFactor();

        if (! is_null($options['x'])) {
            $this->placeContent($options['x'], $options['y']);
        } elseif (! is_null($options['display'])) {
            $this->applyDisplay($options['display']);
        } else {
            $this->window->center();
        }

        if (self::hasDisplayLink()) {
            $this->link_target = new ObjCTarget(fn (CADisplayLink $link) => $this->frameDue($link->timestamp(), $link->targetTimestamp()));
            $this->link = $this->view->displayLinkWithTargetSelector($this->link_target, 'action:');
            $this->link->addToRunLoopForMode(NSRunLoop::mainRunLoop(), NSRunLoopCommonModes);
        }

        $this->stage($options);
    }

    public function backend(): string
    {
        return 'appkit/cocoa';
    }

    public function isKey(): bool
    {
        return $this->key;
    }

    /** The native window, for engines and tests. */
    public function native(): NSWindow
    {
        return $this->window ?? throw new WindowException("Staged window '{$this->name}' was closed.");
    }

    /** The view the frame is drawn in. */
    public function view(): ObjCDrawView
    {
        return $this->view;
    }

    /** Parked SDL windows whose device has let go are destroyed first. */
    public function present(): static
    {
        self::sweepSdlWindows();

        return parent::present();
    }

    /** CADisplayLink on a view is macOS 14 (Darwin 23) and later. */
    public static function hasDisplayLink(): bool
    {
        return version_compare(php_uname('r'), '23.0', '>=');
    }

    protected function nativeCapabilities(): array
    {
        $capabilities = [
            WindowCapability::Position, WindowCapability::AlwaysOnTop, WindowCapability::Focusable,
            WindowCapability::Opacity, WindowCapability::Icon, WindowCapability::KeepAwake,
            WindowCapability::Attention, WindowCapability::ExclusiveFullscreen,
        ];
        if (self::hasDisplayLink()) {
            $capabilities[] = WindowCapability::FrameClock;
        }

        return $capabilities;
    }

    protected function lendingView(): NSView
    {
        return $this->view;
    }

    protected function lendingWindow(): NSWindow
    {
        return $this->native();
    }

    /** The view draws through drawRect: into its own layer, redrawn only where marked. */
    protected function restoreOwnLayer(): void
    {
        $this->view->setWantsLayer(true);
        $this->view->setLayerContentsRedrawPolicy(NSViewLayerContentsRedrawPolicy::ON_SET_NEEDS_DISPLAY);
        $this->view->setLayerBackgroundColor($this->transparent ? null : NSColor::colorWithRedGreenBlueAlpha(0.0, 0.0, 0.0, 1.0));
    }

    protected function nativeSize(): array
    {
        $frame = $this->view->frame();

        return [(int) round($frame->width), (int) round($frame->height)];
    }

    protected function nativeScale(): float
    {
        return $this->native()->backingScaleFactor();
    }

    protected function nativePosition(): array
    {
        $window = $this->native();
        $content = AppkitDisplays::toDesktop($window->contentRectForFrameRect($window->frame()));

        return [$content->x, $content->y];
    }

    protected function nativeSafeArea(): Region
    {
        $bounds = $this->view->bounds();
        $area = $this->view->safeAreaRect();

        return new Region((int) round($area->x), (int) round($bounds->height - $area->y - $area->height), (int) round($area->width), (int) round($area->height));
    }

    protected function nativeDisplay(): Display
    {
        return AppkitDisplays::describe($this->native()->screen() ?? \NSScreen::screens()[0]);
    }

    protected function nativeDisplayModes(): array
    {
        return AppkitDisplays::modes($this->nativeDisplay()->id);
    }

    protected function nativeHdr(): ?Hdr
    {
        return $this->nativeDisplay()->hdr;
    }

    protected function applyTitle(string $title): void
    {
        $this->native()->setTitle($title);
    }

    protected function applyVisible(bool $visible): void
    {
        $window = $this->native();
        if (! $visible) {
            $window->orderOut(null);

            return;
        }
        if ($this->focusable) {
            $this->session->application()->activateIgnoringOtherApps(true);
            $window->makeKeyAndOrderFront(null);
        } else {
            $window->orderFront(null);
        }
        $this->runPending();
    }

    protected function applyMode(WindowMode $mode, Display|DisplayMode|null $on): void
    {
        $window = $this->native();
        // A miniaturized window reads not visible; it is shown, in the Dock.
        if ((! $window->isVisible() && ! $window->isMiniaturized()) || $this->transitioning) {
            $this->pending = [$mode, $on];

            return;
        }
        $spaces = ($window->styleMask() & NSWindowStyleMask::FULL_SCREEN->value) !== 0;
        $stays = $mode === WindowMode::Fullscreen && ($on === null || $on->id === $this->nativeDisplay()->id);
        if ($spaces && ! $stays) {
            // Out of the Space first; the mode asked for follows when AppKit says it left.
            $this->pending = [$mode, $on];
            $this->transitioning = true;
            $window->toggleFullScreen(null);

            return;
        }
        if (! is_null($this->captured) && $mode !== WindowMode::Exclusive) {
            $this->leaveExclusive();
        }
        if ($window->isMiniaturized() && $mode !== WindowMode::Minimized) {
            $window->deminiaturize(null);
        }

        match ($mode) {
            WindowMode::Windowed => $window->isZoomed() ? $window->zoom(null) : null,
            WindowMode::Maximized => $window->isZoomed() ? null : $window->zoom(null),
            WindowMode::Minimized => $window->miniaturize(null),
            WindowMode::Fullscreen => $spaces ? null : $this->enterSpace($on),
            WindowMode::Exclusive => $this->enterExclusive($on),
        };
        $this->syncMode();
    }

    protected function applyResize(int $width, int $height): void
    {
        $this->native()->setContentSize(new NSSize((float) $width, (float) $height));
    }

    protected function applyPosition(int $x, int $y): void
    {
        $this->placeContent($x, $y);
    }

    /** Centre the content area in the display's usable area. */
    protected function applyDisplay(Display $display): void
    {
        [$width, $height] = $this->nativeSize();
        $this->placeContent(
            $display->usable->x + intdiv(max(0, $display->usable->width - $width), 2),
            $display->usable->y + intdiv(max(0, $display->usable->height - $height), 2),
        );
    }

    protected function applyLimits(int $minWidth, int $minHeight, int $maxWidth, int $maxHeight): void
    {
        $unbounded = 3.4028234663852886e38;
        $window = $this->native();
        $window->setContentMinSize(new NSSize((float) $minWidth, (float) $minHeight));
        $window->setContentMaxSize(new NSSize($maxWidth > 0 ? (float) $maxWidth : $unbounded, $maxHeight > 0 ? (float) $maxHeight : $unbounded));
    }

    /**
     * AppKit keeps one exact ratio while the user resizes; a range is kept by
     * correcting the size after each resize.
     */
    protected function applyAspectRatio(float $min, float $max): void
    {
        $exact = $min > 0.0 && $min === $max;
        $this->native()->setContentAspectRatio($exact ? new NSSize($min, 1.0) : new NSSize(0.0, 0.0));
        $this->keepAspect();
    }

    protected function applyResizable(bool $resizable): void
    {
        $this->restyle();
    }

    protected function applyBorderless(bool $borderless): void
    {
        $this->restyle();
    }

    /** CPU presents: On waits for AppKit's display cycle, the others draw at once (applied in applyAddress()). */
    protected function applyVsync(VSync $vsync): void {}

    protected function applyAlwaysOnTop(bool $onTop): void
    {
        if (is_null($this->captured)) {
            $this->native()->setLevel($onTop ? NSFloatingWindowLevel : NSNormalWindowLevel);
        }
    }

    protected function applyFocusable(bool $focusable): void
    {
        $this->native()->setCanBecomeKey($focusable);
    }

    protected function applyOpacity(float $opacity): void
    {
        $this->native()->setAlphaValue($opacity);
    }

    protected function applyKeepAwake(bool $awake): void
    {
        if ($awake && is_null($this->assertion)) {
            $this->assertion = IOPMAssertion::createWithName(kIOPMAssertionTypePreventUserIdleDisplaySleep, kIOPMAssertionLevelOn, "Staged window '{$this->name}'");
        } elseif (! $awake && ! is_null($this->assertion)) {
            IOPMAssertion::release($this->assertion);
            $this->assertion = null;
        }
    }

    /** Bounces the Dock icon until the app is active. */
    protected function applyAttention(): void
    {
        $this->session->application()->requestUserAttention(NSRequestUserAttentionType::CRITICAL);
    }

    /** The Dock icon: macOS has one icon for the app, so the last window to set one wins. */
    protected function applyIcon(string $rgba8, int $width, int $height): void
    {
        $image = $this->image(CFData::create($rgba8), $width, $height, $width * 4, true);
        $this->session->application()->setApplicationIconImage(NSImage::initWithCGImageSize($image, new NSSize((float) $width, (float) $height)));
    }

    protected function applyPixels(string $rgba8, int $width, int $height, array $damage): void
    {
        $this->showFrame(CFData::create($rgba8), $width, $height, $width * 4, $damage);
    }

    protected function applyAddress(int $address, int $width, int $height, int $stride, array $damage): void
    {
        $this->showFrame(CFData::create($address, $stride * $height), $width, $height, $stride, $damage);
    }

    protected function destroyNative(): void
    {
        $this->teardown();
        // windowWillClose: runs inside close(): closed() happens there.
        $this->window?->close();
    }

    protected function forget(): void
    {
        $this->driver->forgetStaged($this->name);
    }

    /**
     * Keep the frame's bytes, copied, as the image drawRect: draws; mark what
     * changed: the regions, mapped through presentRect() to the view, or the
     * whole view for a new size or no damage list.
     *
     * @param  list<Region>  $damage
     */
    protected function showFrame(CFData $bytes, int $width, int $height, int $stride, array $damage): void
    {
        $resized = $this->image_size !== [$width, $height];
        $this->image = $this->image($bytes, $width, $height, $stride, $this->transparent);
        $this->image_size = [$width, $height];

        if ($damage === [] || $resized) {
            $this->view->setNeedsDisplay(true);
        } else {
            foreach ($damage as $region) {
                $this->view->setNeedsDisplayInRect($this->toView($region, $width, $height));
            }
        }
        if ($this->vsync !== VSync::On) {
            $this->view->displayIfNeeded();
        }
    }

    /** @throws WindowException When Core Graphics refuses the image. */
    protected function image(CFData $bytes, int $width, int $height, int $stride, bool $alpha): CGImage
    {
        self::$space ??= CGColorSpace::createWithName(kCGColorSpaceSRGB);
        $provider = CGDataProvider::createWithCFData($bytes);
        $image = is_null($provider) ? null : CGImage::create(
            $width, $height, 8, 32, $stride, self::$space,
            ($alpha ? kCGImageAlphaLast : kCGImageAlphaNoneSkipLast) | kCGBitmapByteOrder32Big, $provider, false, kCGRenderingIntentDefault,
        );

        return $image ?? throw new WindowException("Staged window '{$this->name}' could not show a {$width}x{$height} image.");
    }

    /** drawRect:: the last frame into presentRect(), clipped to what is dirty. */
    protected function draw(NSRect $dirty): void
    {
        $this->draws++;
        $this->dirty[] = $dirty;
        $context = NSGraphicsContext::currentContext()?->CGContext();
        if (is_null($this->image) || is_null($context)) {
            return;
        }
        [$width, $height] = $this->image_size;
        $context->saveGState();
        $context->clipToRect($dirty);
        $context->setInterpolationQuality($this->filter === ScaleFilter::Nearest ? CGInterpolationQuality::NONE : CGInterpolationQuality::HIGH);
        $context->drawImage($this->toView(new Region(0, 0, $width, $height), $width, $height), $this->image);
        $context->restoreGState();
    }

    /** A region of a $width × $height frame as a rect in the view: through presentRect(), into points, y up. */
    protected function toView(Region $region, int $width, int $height): NSRect
    {
        $into = $this->presentRect($width, $height);
        $scale = $this->nativeScale();
        $across = $into->width / max(1, $width);
        $down = $into->height / max(1, $height);
        $x = ($into->x + $region->x * $across) / $scale;
        $y = ($into->y + $region->y * $down) / $scale;
        $w = $region->width * $across / $scale;
        $h = $region->height * $down / $scale;
        $view_height = $this->view->bounds()->height;

        return new NSRect($x, $view_height - $y - $h, $w, $h);
    }

    /** The content area's top-left at $x, $y on the desktop. */
    protected function placeContent(int $x, int $y): void
    {
        $window = $this->native();
        [$width, $height] = $this->nativeSize();
        $frame = $window->frameRectForContentRect(AppkitDisplays::toCocoa($x, $y, (float) $width, (float) $height));
        $window->setFrameOrigin(new NSPoint($frame->x, $frame->y));
    }

    protected function styleMask(): int
    {
        if ($this->borderless) {
            return NSWindowStyleMask::BORDERLESS->value;
        }

        return NSWindowStyleMask::TITLED->value | NSWindowStyleMask::CLOSABLE->value | NSWindowStyleMask::MINIATURIZABLE->value
            | ($this->resizable ? NSWindowStyleMask::RESIZABLE->value : 0);
    }

    /** A new style mask, the content area left where it was. */
    protected function restyle(): void
    {
        if (! is_null($this->captured)) {
            return;
        }
        $window = $this->native();
        $content = $window->contentRectForFrameRect($window->frame());
        $window->setStyleMask($this->styleMask() | ($window->styleMask() & NSWindowStyleMask::FULL_SCREEN->value));
        $window->setFrameDisplay($window->frameRectForContentRect($content), true);
    }

    protected function enterSpace(?Display $on): void
    {
        if (! is_null($on) && $on->id !== $this->nativeDisplay()->id) {
            $this->applyDisplay($on);
        }
        $this->transitioning = true;
        $this->native()->toggleFullScreen(null);
    }

    /** @throws WindowException When the display offers no mode like $mode. */
    protected function enterExclusive(DisplayMode $mode): void
    {
        $native = AppkitDisplays::nativeMode($mode)
            ?? throw new WindowException("Staged window '{$this->name}': display {$mode->displayId} offers no {$mode->width}x{$mode->height} mode at {$mode->refreshRate} Hz and density {$mode->pixelDensity}.");
        $window = $this->native();
        if (! is_null($this->captured) && $this->captured !== $mode->displayId) {
            $this->leaveExclusive();
        }
        if (is_null($this->captured)) {
            $this->before_exclusive = [$window->frame(), $window->styleMask()];
            AppkitDisplays::holdDesktop($mode->displayId);
            CGDisplay::capture($mode->displayId);
            $this->captured = $mode->displayId;
            $window->setStyleMask(NSWindowStyleMask::BORDERLESS);
            $window->setLevel(CGDisplay::shieldingWindowLevel());
        }
        CGDisplay::setDisplayMode($mode->displayId, $native);
        $window->setFrameDisplay(AppkitDisplays::displayRect($mode->displayId), true);
        $window->makeKeyAndOrderFront(null);
    }

    /** The display back on its desktop mode and released; the window as it was. */
    protected function leaveExclusive(): void
    {
        if (is_null($this->captured)) {
            return;
        }
        AppkitDisplays::restoreDesktop($this->captured);
        CGDisplay::release($this->captured);
        $this->captured = null;
        if (! is_null($this->window) && ! is_null($this->before_exclusive)) {
            [$frame, $style] = $this->before_exclusive;
            $this->window->setStyleMask($style);
            $this->window->setLevel($this->always_on_top ? NSFloatingWindowLevel : NSNormalWindowLevel);
            $this->window->setFrameDisplay($frame, true);
        }
        $this->before_exclusive = null;
    }

    /** Report the mode the native window is in, when it changed. Nothing while a transition runs. */
    protected function syncMode(): void
    {
        if ($this->transitioning || is_null($this->window)) {
            return;
        }
        $window = $this->window;
        $actual = match (true) {
            ! is_null($this->captured) => WindowMode::Exclusive,
            ($window->styleMask() & NSWindowStyleMask::FULL_SCREEN->value) !== 0 => WindowMode::Fullscreen,
            $window->isMiniaturized() => WindowMode::Minimized,
            $window->isZoomed() => WindowMode::Maximized,
            default => WindowMode::Windowed,
        };
        if ($actual !== $this->native_mode) {
            $this->native_mode = $actual;
            $this->modeChanged($actual);
        }
    }

    protected function transitionEnded(): void
    {
        $this->transitioning = false;
        $this->syncMode();
        $this->runPending();
    }

    protected function runPending(): void
    {
        if (is_null($this->pending) || ! $this->open) {
            return;
        }
        [$mode, $on] = $this->pending;
        $this->pending = null;
        $this->applyMode($mode, $on);
    }

    protected function didResize(): void
    {
        $this->resized(...$this->nativeSize());
        $this->keepAspect();
        $this->syncMode();
    }

    /** A size outside the aspect range set, corrected; the correction resizes once more. */
    protected function keepAspect(): void
    {
        [$min, $max] = $this->aspect;
        if ($this->clamping || ($min === 0.0 && $max === 0.0) || $min === $max) {
            return;
        }
        [$width, $height] = $this->nativeSize();
        $ratio = $width / max(1, $height);
        $width = match (true) {
            $min > 0.0 && $ratio < $min => (int) round($height * $min),
            $max > 0.0 && $ratio > $max => (int) round($height * $max),
            default => null,
        };
        if (! is_null($width)) {
            $this->clamping = true;
            $this->applyResize($width, $height);
            $this->clamping = false;
        }
    }

    protected function backingChanged(): void
    {
        $scale = $this->native()->backingScaleFactor();
        if ($scale !== $this->last_scale) {
            $this->last_scale = $scale;
            $this->scaleChanged($scale);
        }
    }

    protected function becameKey(): void
    {
        $this->key = true;
        // A staged window has no bar of its own: the app's bar shows while it is key.
        $this->driver->installDefaultMenuBar();
        $this->focused();
    }

    protected function resignedKey(): void
    {
        $this->key = false;
        $this->driver->showDefaultMenuBar();
        $this->focusLost();
    }

    /** The close box: AppKit closes the window unless confirm_close holds it for the app to decide. */
    protected function shouldClose(): bool
    {
        if ($this->confirm_close) {
            $this->closeRequested();

            return false;
        }

        return true;
    }

    protected function willClose(): void
    {
        $this->teardown();
        $this->closed();
        $this->window = null;
        $this->key = false;
    }

    /** What the window holds beyond itself: the captured display, the power assertion, the display link. */
    protected function teardown(): void
    {
        $this->leaveExclusive();
        if (! is_null($this->assertion)) {
            IOPMAssertion::release($this->assertion);
            $this->assertion = null;
        }
        $this->link?->invalidate();
        $this->link = null;
    }

    protected function live(): static
    {
        if (is_null($this->window)) {
            throw new WindowException("Staged window '{$this->name}' was closed.");
        }

        return parent::live();
    }
}
