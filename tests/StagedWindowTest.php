<?php

declare(strict_types=1);

use Jovian\Toolkits\Appkit\Windows\AppkitDisplays;
use Jovian\Toolkits\Appkit\Windows\AppkitStagedWindow;
use Surface\Contracts\Drawing\SurfaceKind;
use Surface\Contracts\Drawing\VSync;
use Surface\Contracts\Framebuffers\Region;
use Surface\Contracts\Windows\Display;
use Surface\Contracts\Windows\DisplayMode;
use Surface\Contracts\Windows\Mail\WindowClosed;
use Surface\Contracts\Windows\Mail\WindowCloseRequested;
use Surface\Contracts\Windows\Mail\WindowFocused;
use Surface\Contracts\Windows\Mail\WindowFrameDue;
use Surface\Contracts\Windows\Mail\WindowModeChanged;
use Surface\Contracts\Windows\Mail\WindowMoved;
use Surface\Contracts\Windows\ScaleFilter;
use Surface\Contracts\Windows\ScaleFit;
use Surface\Contracts\Windows\StagedWindow;
use Surface\Contracts\Windows\StagedWindowDriver;
use Surface\Contracts\Windows\WindowCapability;
use Surface\Contracts\Windows\WindowException;
use Surface\Contracts\Windows\WindowMode;

/*
 * Real AppKit windows through the shared driver. Mail is read from the
 * session's outbox; pumpFor()/pumpUntil() run the real sleeper, which
 * flushes latest mail after each pump.
 */

function stage(string $name = 'stage', array $options = [], int $width = 320, int $height = 200): AppkitStagedWindow
{
    driver()->getStaged($name)?->close();
    takeMail(session());

    return driver()->openStaged($name, $width, $height, $options);
}

/** Mail of class $class taken so far, pumping until one arrives or $seconds pass. */
function mailOf(string $class, float $seconds = 3.0): array
{
    $seen = [];
    pumpUntil(function () use ($class, &$seen): bool {
        foreach (takeMail(session()) as $mail) {
            if ($mail instanceof $class) {
                $seen[] = $mail;
            }
        }

        return $seen !== [];
    }, $seconds);

    return $seen;
}

afterEach(function (): void {
    driver()->closeAllStaged();
    pumpFor(0.05);
});

it('stages windows through the AppKit driver and names its backend and capabilities', function (): void {
    $window = stage('stage', ['title' => 'Doom']);

    expect(driver())->toBeInstanceOf(StagedWindowDriver::class)
        ->and($window)->toBeInstanceOf(StagedWindow::class)
        ->and($window->backend())->toBe('appkit/cocoa')
        ->and($window->title())->toBe('Doom')
        ->and($window->native()->title())->toBe('Doom')
        ->and($window->size())->toBe([320, 200])
        ->and($window->scale())->toBe($window->native()->backingScaleFactor())
        ->and($window->isVisible())->toBeTrue()
        ->and($window->native()->isVisible())->toBeTrue()
        ->and($window->supports(WindowCapability::ExclusiveFullscreen))->toBeTrue()
        ->and($window->supports(WindowCapability::FrameClock))->toBe(AppkitStagedWindow::hasDisplayLink())
        ->and($window->supports(WindowCapability::HitTest))->toBeFalse()
        ->and(driver()->getStaged('stage'))->toBe($window)
        ->and(fn () => driver()->openStaged('stage', 1, 1))->toThrow(WindowException::class, "A staged window named 'stage' is already open.")
        ->and(fn () => $window->hitTest(null))->toThrow(WindowException::class, "Staged window 'stage' cannot take a hit test: the appkit/cocoa backend does not support it.");
});

it('takes focus and posts it, borderless too', function (): void {
    $window = stage('stage', ['borderless' => true]);

    expect(mailOf(WindowFocused::class))->toEqual([new WindowFocused('stage')])
        ->and($window->isKey())->toBeTrue()
        ->and($window->native()->styleMask())->toBe(NSWindowStyleMask::BORDERLESS->value);
});

it('presents only the damaged rects of a dirty framebuffer, at once with vsync off', function (): void {
    $window = stage('stage', ['vsync' => VSync::Off]);
    $frame = $window->framebuffer();
    [$width, $height] = $window->pixelSize();
    $window->present();
    $first = $window->draws;

    $frame->setPixel(4, 4, 0xFF0000FF);
    $window->present();

    $last = end($window->dirty);
    expect($first)->toBeGreaterThan(0)
        ->and($window->draws)->toBeGreaterThan($first)
        ->and($last->width)->toBeLessThan($width / $window->scale())
        ->and($last->height)->toBeLessThan($height / $window->scale());
});

it('leaves the draw to AppKit\'s display cycle with vsync on', function (): void {
    $window = stage('stage', ['vsync' => VSync::On]);
    pumpFor(0.1);
    $window->framebuffer();
    $before = $window->draws;

    $window->present();
    $straight = $window->draws;
    pumpUntil(fn (): bool => $window->draws > $before, 1.0);

    expect($straight)->toBe($before)
        ->and($window->draws)->toBeGreaterThan($before);
});

it('draws a small framebuffer by the scaling fit and filter', function (): void {
    $window = stage('stage', ['vsync' => VSync::Off]);
    $window->setScaling(ScaleFilter::Nearest, ScaleFit::Integer);
    $window->framebuffer('full', 64, 40)->setPixel(0, 0, 0xFFFFFFFF);

    $window->present();

    [$across, $down] = $window->pixelSize();
    $rect = $window->presentRect(64, 40);
    expect($window->draws)->toBeGreaterThan(0)
        ->and($rect->width % 64)->toBe(0)
        ->and($rect->x)->toBe(intdiv($across - $rect->width, 2))
        ->and($rect->y)->toBe(intdiv($down - $rect->height, 2));
});

it('lists displays and their modes, and names the display a window is on', function (): void {
    $window = stage();
    $displays = driver()->displays();
    $display = $window->display();
    $modes = $window->displayModes();

    expect($displays)->not->toBeEmpty()
        ->and($displays[0])->toBeInstanceOf(Display::class)
        ->and([$displays[0]->bounds->x, $displays[0]->bounds->y])->toBe([0, 0])
        ->and(driver()->primaryDisplay()->id)->toBe(CGDisplay::mainDisplayID())
        ->and(array_map(fn (Display $d): int => $d->id, $displays))->toContain($display->id)
        ->and($display->usable->height)->toBeLessThan($display->bounds->height)
        ->and($display->current->refreshRate)->toBeGreaterThan(0.0)
        ->and($modes)->not->toBeEmpty()
        ->and(array_filter($modes, fn (DisplayMode $m): bool => $m->equals($display->current)))->not->toBeEmpty()
        ->and($window->hdr())->not->toBeNull();
});

it('moves on the desktop and posts where it went', function (): void {
    $window = stage();

    $window->move(120, 140);

    expect($window->position())->toBe([120, 140])
        ->and(mailOf(WindowMoved::class))->toContainEqual(new WindowMoved('stage', 120, 140));
});

it('bounds its size and keeps an exact aspect ratio natively and a range by correction', function (): void {
    $window = stage();

    $window->setLimits(200, 100, 900, 700);
    expect($window->native()->contentMinSize())->toEqual(new NSSize(200.0, 100.0))
        ->and($window->native()->contentMaxSize())->toEqual(new NSSize(900.0, 700.0));

    $window->setAspectRatio(2.0, 2.0);
    expect($window->native()->contentAspectRatio())->toEqual(new NSSize(2.0, 1.0));

    $window->setAspectRatio(1.0, 1.5);
    $window->resize(600, 200);
    expect($window->size())->toBe([300, 200]);
});

it('toggles its style natively', function (): void {
    $window = stage('stage', ['transparent' => true]);

    $window->setBorderless(true);
    expect($window->native()->styleMask())->toBe(NSWindowStyleMask::BORDERLESS->value)
        ->and($window->size())->toBe([320, 200])
        ->and($window->native()->canBecomeKeyWindow())->toBeTrue()   // a plain borderless NSWindow never can
        ->and($window->native()->canBecomeMainWindow())->toBeTrue();
    $window->setBorderless(false)->setResizable(false);
    expect($window->native()->styleMask() & NSWindowStyleMask::TITLED->value)->toBe(NSWindowStyleMask::TITLED->value)
        ->and($window->native()->styleMask() & NSWindowStyleMask::RESIZABLE->value)->toBe(0);

    $window->setAlwaysOnTop(true)->setFocusable(false)->setOpacity(0.5);
    expect($window->native()->level())->toBe(NSFloatingWindowLevel)
        ->and($window->native()->canBecomeKeyWindow())->toBeFalse()
        ->and($window->native()->canBecomeMainWindow())->toBeFalse()
        ->and($window->native()->alphaValue())->toBe(0.5)
        ->and($window->native()->isOpaque())->toBeFalse()
        ->and($window->isTransparent())->toBeTrue();
});

it('maximizes, minimizes and restores, posting each mode it entered', function (): void {
    $window = stage();
    pumpFor(0.2);

    $window->setMode(WindowMode::Maximized);
    expect(mailOf(WindowModeChanged::class))->toEqual([new WindowModeChanged('stage', WindowMode::Maximized)])
        ->and($window->native()->isZoomed())->toBeTrue();

    $window->setMode(WindowMode::Windowed);
    expect(mailOf(WindowModeChanged::class))->toEqual([new WindowModeChanged('stage', WindowMode::Windowed)]);

    $window->setMode(WindowMode::Minimized);
    expect(mailOf(WindowModeChanged::class))->toEqual([new WindowModeChanged('stage', WindowMode::Minimized)])
        ->and($window->native()->isMiniaturized())->toBeTrue();

    $window->setMode(WindowMode::Windowed);
    expect(mailOf(WindowModeChanged::class))->toEqual([new WindowModeChanged('stage', WindowMode::Windowed)])
        ->and($window->native()->isMiniaturized())->toBeFalse();
});

it('enters and leaves native full screen', function (): void {
    $window = stage();
    pumpFor(0.2);

    $window->setMode(WindowMode::Fullscreen);
    expect(mailOf(WindowModeChanged::class, 6.0))->toEqual([new WindowModeChanged('stage', WindowMode::Fullscreen)])
        ->and($window->native()->styleMask() & NSWindowStyleMask::FULL_SCREEN->value)->toBe(NSWindowStyleMask::FULL_SCREEN->value);

    $window->setMode(WindowMode::Windowed);
    expect(mailOf(WindowModeChanged::class, 6.0))->toEqual([new WindowModeChanged('stage', WindowMode::Windowed)])
        ->and($window->native()->styleMask() & NSWindowStyleMask::FULL_SCREEN->value)->toBe(0);
});

it('switches the display mode for exclusive fullscreen and puts it back', function (): void {
    $window = stage();
    pumpFor(0.2);
    $display = $window->display();
    $other = null;
    foreach ($window->displayModes() as $mode) {
        if ($mode->width !== $display->current->width) {
            $other = $mode;
            break;
        }
    }
    expect($other)->not->toBeNull();

    $window->setMode(WindowMode::Exclusive, $other);
    $held = CGDisplay::copyDisplayMode($display->id);
    expect($window->mode())->toBe(WindowMode::Exclusive)
        ->and($window->exclusiveMode())->toBe($other)
        ->and($held->getWidth())->toBe($other->width)
        ->and($window->native()->level())->toBe(CGDisplay::shieldingWindowLevel())
        ->and($window->display()->desktop->width)->toBe($display->current->width)
        ->and(mailOf(WindowModeChanged::class))->toEqual([new WindowModeChanged('stage', WindowMode::Exclusive)]);

    $window->setMode(WindowMode::Windowed);
    expect(CGDisplay::copyDisplayMode($display->id)->getWidth())->toBe($display->current->width)
        ->and($window->native()->level())->toBe(NSNormalWindowLevel)
        ->and($window->native()->styleMask() & NSWindowStyleMask::TITLED->value)->toBe(NSWindowStyleMask::TITLED->value)
        ->and(mailOf(WindowModeChanged::class))->toEqual([new WindowModeChanged('stage', WindowMode::Windowed)]);
});

it('gives the display back when an exclusive window closes', function (): void {
    $window = stage();
    pumpFor(0.2);
    $display = $window->display();
    $other = array_values(array_filter($window->displayModes(), fn (DisplayMode $m): bool => $m->width !== $display->current->width))[0];

    $window->setMode(WindowMode::Exclusive, $other);
    $window->close();

    expect(CGDisplay::copyDisplayMode($display->id)->getWidth())->toBe($display->current->width);
});

it('ticks the frame clock as latest-wins mail', function (): void {
    stage();

    $ticks = mailOf(WindowFrameDue::class, 2.0);

    expect($ticks)->not->toBeEmpty()
        ->and($ticks[0]->targetTimestamp)->toBeGreaterThan($ticks[0]->timestamp);
})->skip(! AppkitStagedWindow::hasDisplayLink(), 'CADisplayLink on a view is macOS 14 and later');

it('keeps the display awake, asks for attention, sets the Dock icon and reads its safe area', function (): void {
    $window = stage();

    $window->keepAwake(true)->requestAttention()->setIcon(str_repeat("\x20\x80\xff\xff", 32 * 32), 32, 32);
    $area = $window->safeArea();

    expect($window->isKeptAwake())->toBeTrue()
        ->and(session()->application()->applicationIconImage()->size())->toEqual(new NSSize(32.0, 32.0))
        ->and($area)->toBeInstanceOf(Region::class)
        ->and($area->width)->toBeLessThanOrEqual(320)
        ->and($area->height)->toBeLessThanOrEqual(200);

    $window->keepAwake(false);
    session()->application()->setApplicationIconImage(null);
    expect($window->isKeptAwake())->toBeFalse();
});

it('fails where I/O Kit or Core Graphics answers an error, naming the call and its code', function (): void {
    $window = stage();
    $assertion = new ReflectionProperty(AppkitStagedWindow::class, 'assertion');
    $captured = new ReflectionProperty(AppkitStagedWindow::class, 'captured');
    $desktop = new ReflectionProperty(AppkitDisplays::class, 'desktop');
    $bogus = 0x7FFF_FFF0;   // neither an assertion I/O Kit made nor a display

    $window->keepAwake(true);
    $held = $assertion->getValue($window);
    $assertion->setValue($window, $bogus);
    expect(fn () => $window->keepAwake(false))
        ->toThrow(WindowException::class, "Staged window 'stage': IOPMAssertionRelease failed with IOReturn 0xe00002c2.");   // kIOReturnBadArgument
    $assertion->setValue($window, $held);
    $window->keepAwake(false);

    $captured->setValue($window, $bogus);
    expect(fn () => $window->close())
        ->toThrow(WindowException::class, "Staged window 'stage': CGDisplayRelease failed with CGError ".kCGErrorIllegalArgument.'.');
    $captured->setValue($window, null);
    $window->close();

    $desktop->setValue(null, [$bogus => CGDisplay::copyDisplayMode(CGDisplay::mainDisplayID())]);
    expect(fn () => AppkitDisplays::restoreDesktop($bogus))
        ->toThrow(WindowException::class, "Display {$bogus}: CGDisplaySetDisplayMode failed with CGError ".kCGErrorFailure.'.')
        ->and(array_keys($desktop->getValue()))->toBe([$bogus]);
    $desktop->setValue(null, []);
});

it('closes on the close box, or asks first with confirm_close', function (): void {
    $asking = stage('asking', ['confirm_close' => true]);
    $asking->native()->performClose(null);
    expect($asking->isOpen())->toBeTrue()
        ->and(takeMail(session()))->toContainEqual(new WindowCloseRequested('asking'));

    $asking->close();
    expect($asking->isOpen())->toBeFalse()
        ->and(driver()->hasStaged('asking'))->toBeFalse();

    $plain = stage('plain');
    $plain->native()->performClose(null);
    expect($plain->isOpen())->toBeFalse()
        ->and(takeMail(session()))->toContainEqual(new WindowClosed('plain'))
        ->and(fn () => $plain->setTitle('x'))->toThrow(WindowException::class, "Staged window 'plain' was closed.");
});

it('lends its view a Metal layer and takes its own drawing back', function (): void {
    $window = stage('stage', ['vsync' => VSync::Adaptive]);
    $layer_before = $window->view()->layer()->pointer();

    $surface = $window->lend(SurfaceKind::METAL_LAYER, new LayerBorrower());

    expect($surface->handle('layer'))->toBe($window->view()->layer()->pointer())
        ->and($surface->vsync())->toBe(VSync::Adaptive);

    $window->reclaim();
    expect($window->view()->layer()->pointer())->not->toBe($surface->handle('layer'))
        ->and($layer_before)->toBeInt();
})->skip(! class_exists(CAMetalLayer::class), 'needs ext-metal');

it('lends a GL context over its view', function (): void {
    $window = stage();

    $surface = $window->lend(SurfaceKind::GL_CONTEXT, new LayerBorrower());

    expect($surface->handle('context'))->toBeGreaterThan(0)
        ->and($window->glView()?->superview()?->pointer())->toBe($window->view()->pointer());

    $window->reclaim();
    expect($window->glView())->toBeNull();
})->skip(! function_exists('CGLGetCurrentContext'), 'needs ext-opengl');
