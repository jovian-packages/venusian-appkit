<?php

declare(strict_types=1);

use Jovian\Toolkits\Appkit\Windows\AppkitWindow;
use Surface\Contracts\Windows\Mail\MenuActivated;
use Surface\Contracts\Windows\Mail\MenuRadioChosen;
use Surface\Contracts\Windows\Mail\MenuToggled;
use Surface\Contracts\Windows\WindowException;

beforeEach(function (): void {
    driver()->closeAll();
    pumpFor(0.05);
    takeMail(session());
});

const CHOICE_MENU = [
    ['id' => 'wrap', 'label' => 'Wrap', 'toggle' => true],
    ['separator' => true],
    ['id' => 'case.none', 'label' => 'None', 'radio' => 'case', 'on' => true],
    ['id' => 'case.all', 'label' => 'All', 'radio' => 'case'],
];

/** The menu mail posted since the last read. */
function menuMail(): array
{
    return array_values(array_filter(takeMail(session()), fn (object $mail): bool => $mail instanceof MenuActivated || $mail instanceof MenuToggled || $mail instanceof MenuRadioChosen));
}

/** Right-click $view at its centre and run $inside on the open menu while AppKit tracks it, then close it. */
function rightClickMenu(AppkitWindow $window, NSView $view, Closure $inside): void
{
    $at = canvasWindowPoint($window, $view, $view->bounds()->width / 2, $view->bounds()->height / 2);
    $done = whileTracking(function () use ($window, $inside): void {
        $menu = $window->rightClicks()->openMenu();
        $inside($menu);
        $menu->menu()->cancelTracking();
    });
    session()->see(NSEvent::mouseEventWithTypeLocationModifierFlagsTimestampWindowNumberContextEventNumberClickCountPressure(
        NSEventType::RIGHT_MOUSE_DOWN, $at, 0, 0.0, $window->native()->windowNumber(), null, 0, 1, 1.0,
    ));
    $done();
}

function shownLabel(): array
{
    $window = driver()->open('main', 300, 200);
    $label = $window->column('m')->label('l', 'Label')->minSize(120, 40);
    $label->setContextMenu(CHOICE_MENU);
    $window->present();
    pumpUntil(fn (): bool => $label->native()->bounds()->width > 0.0, 3.0);
    takeMail(session());

    return [$window, $label];
}

it('posts a context toggle and keeps it for the next open and in contextMenu()', function (): void {
    [$window, $label] = shownLabel();

    rightClickMenu($window, $label->native(), fn ($menu) => choose($menu->item('wrap')));
    $next = null;
    rightClickMenu($window, $label->native(), function ($menu) use (&$next): void {
        $next = $menu->item('wrap')->state();
    });

    expect(menuMail())->toEqual([new MenuToggled('main', 'wrap', true)])
        ->and($next)->toBe(NSControlStateValue::ON)
        ->and($label->contextMenu()->items()['wrap']->isOn())->toBeTrue();
    $window->close();
});

it('posts a context radio choice once and keeps it', function (): void {
    [$window, $label] = shownLabel();

    rightClickMenu($window, $label->native(), fn ($menu) => choose($menu->item('case.all')));
    $states = null;
    rightClickMenu($window, $label->native(), function ($menu) use (&$states): void {
        choose($menu->item('case.all'));
        $states = [$menu->item('case.none')->state(), $menu->item('case.all')->state()];
    });

    expect(menuMail())->toEqual([new MenuRadioChosen('main', 'case', 'case.all')])
        ->and($states)->toBe([NSControlStateValue::OFF, NSControlStateValue::ON])
        ->and($label->contextMenu()->radios())->toBe(['case' => 'case.all']);
    $window->close();
});

it('opens a menu at a point from code, returning when it closes, its choices posted and not kept', function (): void {
    [$window, $label] = shownLabel();
    $done = whileTracking(function () use ($label): void {
        $open = $label->openPopup();
        choose($open->item('wrap'));
        $open->menu()->cancelTracking();
    });

    $label->popupMenu(CHOICE_MENU, 10.0, 5.0);
    $done();

    expect(menuMail())->toEqual([new MenuToggled('main', 'wrap', true)])
        ->and($label->openPopup())->toBeNull()
        ->and($label->contextMenu()->items()['wrap']->isOn())->toBeFalse();
    $window->close();
});

it('refuses to open a menu in a window not shown', function (): void {
    $window = driver()->open('main', 300, 200);
    $label = $window->column('m')->label('l', 'Label');

    expect(fn () => $label->popupMenu(CHOICE_MENU, 0.0, 0.0))->toThrow(WindowException::class, "'m.l' cannot open a menu: it is not shown.");
    $window->close();
});

it('frees a primitive removed while its popped menu is open, posting nothing', function (): void {
    [$window, $label] = shownLabel();
    $freed = WeakReference::create($label);
    $done = whileTracking(function () use (&$label): void {
        $open = $label->openPopup();
        $label->remove();
        $open->menu()->cancelTracking();
    });

    $label->popupMenu(CHOICE_MENU, 10.0, 5.0);
    $done();
    unset($label);
    pumpFor(0.1);
    gc_collect_cycles();

    expect(menuMail())->toBe([])
        ->and($freed->get())->toBeNull();
    $window->close();
});
