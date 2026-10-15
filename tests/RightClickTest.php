<?php

declare(strict_types=1);

use Jovian\Toolkits\Appkit\Windows\AppkitWindow;
use Surface\Contracts\Windows\Mail\MenuActivated;
use Surface\Contracts\Windows\Mail\View\RightClicked;
use Surface\Contracts\Windows\Mail\View\Toggled;
use Surface\Contracts\Windows\Mail\WindowRightClicked;

beforeEach(function (): void {
    driver()->closeAll();
    pumpFor(0.05);
    takeMail(session());
});

/**
 * The window (base) point of ($x, $y) measured from $view's top-left, once laid out; its centre for
 * null (a push button's bounds run past its bezel, outside what its superview shows).
 */
function windowPointOf(AppkitWindow $window, NSView $view, ?float $x = null, ?float $y = null): NSPoint
{
    $window->present();
    pumpUntil(fn (): bool => $view->bounds()->width > 0.0, 3.0);
    $x ??= $view->bounds()->width / 2;
    $y ??= $view->bounds()->height / 2;
    // window → view is a translation, with y turned round when the view is flipped: invert it.
    $origin = $view->convertPointFromView(new NSPoint(0.0, 0.0), null);
    $sign = $view->convertPointFromView(new NSPoint(0.0, 1.0), null)->y - $origin->y;
    $ly = $view->isFlipped() ? $y : $view->bounds()->height - $y;

    return new NSPoint($x - $origin->x, ($ly - $origin->y) / $sign);
}

function mouseDown(AppkitWindow $window, NSPoint $at, NSEventType $type = NSEventType::RIGHT_MOUSE_DOWN, int $flags = 0, ?int $windowNumber = null): NSEvent
{
    return NSEvent::mouseEventWithTypeLocationModifierFlagsTimestampWindowNumberContextEventNumberClickCountPressure(
        $type, $at, $flags, 0.0, $windowNumber ?? $window->native()->windowNumber(), null, 0, 1, 1.0,
    );
}

/** @return list<object> the right-click mail posted since the last read */
function appkitRightClickMail(): array
{
    return array_values(array_filter(takeMail(session()), fn (object $mail): bool => $mail instanceof RightClicked || $mail instanceof WindowRightClicked));
}

it('posts view.right-clicked for the innermost primitive under a right press, top-left in its own coordinates', function (): void {
    $window = driver()->open('main', 300, 200);
    $go = $window->column('m')->row('r')->button('go', 'Go');
    $at = windowPointOf($window, $go->native());
    takeMail(session());
    session()->see(mouseDown($window, $at));

    $mail = appkitRightClickMail();
    expect($mail)->toHaveCount(1)
        ->and($mail[0])->toBeInstanceOf(RightClicked::class)
        ->and([$mail[0]->window, $mail[0]->path, $mail[0]->uuid])->toBe(['main', 'm.r.go', $go->uuid()])
        ->and([round($mail[0]->x), round($mail[0]->y)])->toBe([round($go->native()->bounds()->width / 2), round($go->native()->bounds()->height / 2)]);
});

it('reads Ctrl with the primary button as a right click, and posts nothing for a plain click or another window', function (): void {
    $window = driver()->open('main', 300, 200);
    $go = $window->column('m')->button('go', 'Go');
    $at = windowPointOf($window, $go->native());
    takeMail(session());
    session()->see(mouseDown($window, $at, NSEventType::LEFT_MOUSE_DOWN));
    session()->see(mouseDown($window, $at, windowNumber: $window->native()->windowNumber() + 1000));
    $none = appkitRightClickMail();
    session()->see(mouseDown($window, $at, NSEventType::LEFT_MOUSE_DOWN, NSEventModifierFlags::CONTROL->value));

    expect($none)->toBe([])
        ->and(array_map(fn (object $mail): string => $mail->name(), appkitRightClickMail()))->toBe(['view.right-clicked.main.m.go']);
});

it('posts window.right-clicked on content with no primitive, top-left in content coordinates', function (): void {
    $window = driver()->open('bare', 300, 200);
    $content = $window->native()->contentView();
    $at = windowPointOf($window, $content, 30.0, 40.0);
    takeMail(session());
    session()->see(mouseDown($window, $at));

    $mail = appkitRightClickMail();
    expect($mail)->toHaveCount(1)
        ->and($mail[0])->toBeInstanceOf(WindowRightClicked::class)
        ->and([$mail[0]->window, round($mail[0]->x), round($mail[0]->y)])->toBe(['bare', 30.0, 40.0]);
});

it('posts nothing for a disabled primitive or one inside a disabled group', function (): void {
    $window = driver()->open('main', 300, 200);
    $row = $window->column('m')->row('r');
    $go = $row->button('go', 'Go');
    $at = windowPointOf($window, $go->native());
    takeMail(session());
    $go->setEnabled(false);
    session()->see(mouseDown($window, $at));
    $own = appkitRightClickMail();
    $go->setEnabled(true);
    $row->setEnabled(false);
    session()->see(mouseDown($window, $at));

    expect([$own, appkitRightClickMail()])->toBe([[], []]);
});

it('sees right presses the session pumps, and stops when its window closes', function (): void {
    $window = driver()->open('main', 300, 200);
    $go = $window->column('m')->button('go', 'Go');
    $at = windowPointOf($window, $go->native());
    takeMail(session());
    session()->application()->postEventAtStart(mouseDown($window, $at), true);
    pumpFor(0.1);
    $pumped = array_map(fn (object $mail): string => $mail->name(), appkitRightClickMail());
    $clicks = $window->rightClicks();
    $window->close();
    pumpFor(0.05);

    expect($pumped)->toBe(['view.right-clicked.main.m.go'])
        ->and($clicks->watching())->toBeFalse();
});

/** @return list<string> the names of the right-click, toggle and menu mail posted since the last read */
function appkitContextMail(): array
{
    return array_values(array_map(fn (object $mail): string => $mail->name(), array_filter(takeMail(session()), fn (object $mail): bool => $mail instanceof RightClicked || $mail instanceof WindowRightClicked || $mail instanceof MenuActivated || $mail instanceof Toggled)));
}

/**
 * Run $inside once while a menu tracks: a menu tracks in its own run loop mode, and a source in the
 * common modes runs inside it. The returned closure takes the source off.
 */
function whileTracking(Closure $inside): Closure
{
    [$read, $write] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    $descriptor = CFFileDescriptor::create($read, false, function () use ($inside): void {
        $inside();
    });
    $source = $descriptor->createRunLoopSource(0);
    CFRunLoop::getMain()->addSource($source, kCFRunLoopCommonModes);
    $descriptor->enableCallBacks(kCFFileDescriptorReadCallBack);
    fwrite($write, 'x');

    return function () use ($descriptor, $source, $read, $write): void {
        CFRunLoop::getMain()->removeSource($source, kCFRunLoopCommonModes);
        $descriptor->invalidate();
        fclose($write);
        fclose($read);
    };
}

it('opens a primitive\'s context menu at the right press, after its mail, and posts menu.activated for a chosen item', function (): void {
    $window = driver()->open('main', 300, 200);
    $go = $window->column('m')->button('go', 'Go')->setContextMenu([
        ['label' => 'Download'],
        ['separator' => true],
        ['label' => 'Share', 'items' => [['label' => 'Copy Link']]],
    ]);
    $at = windowPointOf($window, $go->native());
    takeMail(session());
    $during = [];
    $done = whileTracking(function () use ($window, &$during): void {
        $menu = $window->rightClicks()->openMenu();
        $during = appkitContextMail();
        $menu->item('share.copy-link')->menu()->performActionForItemAtIndex(0);
        $menu->menu()->cancelTracking();
    });
    session()->see(mouseDown($window, $at));
    $done();

    expect($during)->toBe(['view.right-clicked.main.m.go'])
        ->and(appkitContextMail())->toBe(['menu.activated.main.share.copy-link']);
});

it('opens the nearest group\'s menu and withholds the press that opened it; none and nothing withheld without a menu or when disabled', function (): void {
    $window = driver()->open('main', 300, 200);
    $outer = $window->column('m');
    $column = $outer->column('c')->setContextMenu([['label' => 'Refresh']]);
    $label = $column->label('l', 'Label');
    $plain = $outer->button('plain', 'Plain');
    $at = windowPointOf($window, $label->native());
    takeMail(session());
    $done = whileTracking(function () use ($window): void {
        $menu = $window->rightClicks()->openMenu();
        $menu->menu()->performActionForItemAtIndex(0);
        $menu->menu()->cancelTracking();
    });
    $press = mouseDown($window, $at, NSEventType::LEFT_MOUSE_DOWN, NSEventModifierFlags::CONTROL->value);
    session()->see($press);
    $done();
    $withheld = session()->withheld();
    $opened = appkitContextMail();
    $first = $window->rightClicks()->openMenu();
    session()->withhold(null);
    session()->see(mouseDown($window, windowPointOf($window, $plain->native())));
    $none = session()->withheld();
    $column->disable();
    session()->see(mouseDown($window, $at));

    expect($withheld)->toBe($press)
        ->and($opened)->toBe(['view.right-clicked.main.m.c.l', 'menu.activated.main.refresh'])
        ->and($none)->toBeNull()
        ->and($window->rightClicks()->openMenu())->toBe($first)
        ->and(session()->withheld())->toBeNull()
        ->and(appkitContextMail())->toBe(['view.right-clicked.main.m.plain']);
});

it('dispatches what it pumps, except an event a tap withholds', function (): void {
    $window = driver()->open('press', 200, 100);
    $go = $window->column('m')->button('go', 'Go');
    $centre = windowPointOf($window, $go->native());
    $click = function () use ($window, $centre): void {
        foreach ([NSEventType::LEFT_MOUSE_UP, NSEventType::LEFT_MOUSE_DOWN] as $type) {
            session()->application()->postEventAtStart(NSEvent::mouseEventWithTypeLocationModifierFlagsTimestampWindowNumberContextEventNumberClickCountPressure(
                $type, $centre, 0, 0.0, $window->native()->windowNumber(), null, 0, 1, 1.0,
            ), true);
        }
        pumpFor(0.2);
    };
    $clicked = fn (): array => array_values(array_map(fn (object $mail): string => $mail->name(), array_filter(takeMail(session()), fn (object $mail): bool => $mail instanceof Surface\Contracts\Windows\Mail\View\ButtonClicked)));
    takeMail(session());

    $click();
    $dispatched = $clicked();
    $tap = new class(session()) implements Surface\Contracts\HumanInput\InputTap {
        public function __construct(private readonly Jovian\Toolkits\Appkit\Bridge\AppkitSession $session) {}

        public function see(object $native_event): void
        {
            if ($native_event instanceof NSEvent && $native_event->type() === NSEventType::LEFT_MOUSE_DOWN) {
                $this->session->withhold($native_event);
            }
        }
    };
    session()->tap($tap);
    $click();
    session()->untap($tap);

    expect($dispatched)->toBe(['view.clicked.press.m.go'])
        ->and($clicked())->toBe([])
        ->and(session()->withheld())->toBeNull();
    $window->close();
});
