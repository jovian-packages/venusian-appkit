<?php

declare(strict_types=1);

use Surface\Contracts\Windows\Mail\MenuActivated;
use Surface\Contracts\Windows\Mail\MenuToggled;
use Surface\Contracts\Windows\Mail\QuitRequested;
use Surface\Contracts\Windows\WindowException;

beforeEach(function (): void {
    driver()->closeAll();
    pumpFor(0.05);
    takeMail(session());
});

it('builds the profile into the window bar, app menu first', function (): void {
    $bar = driver()->open('main', 320, 200, driver()->profile('main'))->menuBar();
    $menu = $bar->menu();

    expect($menu->numberOfItems())->toBe(2)
        ->and($menu->itemAtIndex(0)->submenu()->numberOfItems())->toBe(3)
        ->and($menu->itemAtIndex(0)->submenu()->itemAtIndex(1)->isSeparatorItem())->toBeTrue()
        ->and($bar->item('app.quit')->keyEquivalent())->toBe('q')
        ->and($bar->item('view.refresh')->keyEquivalent())->toBe('r')
        ->and($bar->item('grid')->state())->toBe(NSControlStateValue::OFF);
});

it('installs the key window bar as the app bar', function (): void {
    $window = driver()->open('main', 320, 200, driver()->profile('main'))->present();

    expect(pumpUntil(fn () => $window->isKey(), 5.0))->toBeTrue()
        ->and(session()->application()->mainMenu())->toBe($window->menuBar()->menu());
});

it('shows the default bar while a window without a bar is key', function (): void {
    driver()->setDefaultMenuBar(driver()->profile('main'));
    $tools = driver()->open('tools', 320, 200, driver()->profile('tools'))->present();
    expect(pumpUntil(fn () => $tools->isKey(), 5.0))->toBeTrue()
        ->and(session()->application()->mainMenu())->toBe($tools->menuBar()->menu());

    $plain = driver()->open('plain', 320, 200)->present();
    expect(pumpUntil(fn () => $plain->isKey(), 5.0))->toBeTrue();
    $bar = session()->application()->mainMenu();

    expect($plain->isKey())->toBeTrue()
        ->and($bar)->not->toBe($tools->menuBar()->menu())
        ->and($bar->itemAtIndex(1)->submenu()->itemAtIndex(1)->title())->toBe('Refresh');

    takeMail(session());
    choose($bar->itemAtIndex(1)->submenu()->itemAtIndex(1));
    expect(takeMail(session()))->toEqual([new MenuActivated('', 'view.refresh')]);
});

it('posts MenuActivated for an action item', function (): void {
    $window = driver()->open('main', 320, 200, driver()->profile('main'));

    choose($window->menuBar()->item('view.refresh'));

    expect(takeMail(session()))->toEqual([new MenuActivated('main', 'view.refresh')]);
});

it('flips a toggle and posts MenuToggled with the new state', function (): void {
    $window = driver()->open('main', 320, 200, driver()->profile('main'));

    choose($window->menuBar()->item('grid'));
    expect($window->isToggled('grid'))->toBeTrue();

    choose($window->menuBar()->item('grid'));
    expect($window->isToggled('grid'))->toBeFalse()
        ->and(takeMail(session()))->toEqual([new MenuToggled('main', 'grid', true), new MenuToggled('main', 'grid', false)]);
});

it('sets a toggle without posting', function (): void {
    $window = driver()->open('main', 320, 200, driver()->profile('main'));

    $window->setToggle('grid', true);

    expect($window->isToggled('grid'))->toBeTrue()
        ->and(takeMail(session()))->toBe([])
        ->and(fn () => $window->setToggle('view.refresh', true))->toThrow(WindowException::class, 'No toggle item');
});

it('shows the standard About panel for the About item and posts nothing', function (): void {
    $window = driver()->open('main', 320, 200, driver()->profile('main'));

    choose($window->menuBar()->item('app.about'));
    pumpFor(0.1);
    $panels = array_values(array_filter(
        session()->application()->windows(),
        fn (NSWindow $w): bool => $w->className() === 'NSPanel' && $w->isVisible(),
    ));

    expect(takeMail(session()))->toBe([])
        ->and($panels)->toHaveCount(1);

    $panels[0]->close();
    pumpFor(0.05);
});

it('posts QuitRequested for Quit and quits nothing', function (): void {
    $window = driver()->open('main', 320, 200, driver()->profile('main'));

    choose($window->menuBar()->item('app.quit'));

    expect(takeMail(session()))->toEqual([new QuitRequested('main')])
        ->and($window->isOpen())->toBeTrue()
        ->and(session()->connected())->toBeTrue();
});

it('swaps the bar by profile name', function (): void {
    $window = driver()->open('main', 320, 200);

    expect($window->menuBar())->toBeNull()
        ->and(fn () => $window->isToggled('grid'))->toThrow(WindowException::class, 'no menu bar');

    $window->setMenuBar('tools');
    expect($window->menuBar()->item('tools.measure')->title())->toBe('Measure')
        ->and(fn () => $window->setMenuBar('nope'))->toThrow(WindowException::class, "No menu profile named 'nope'");
});

it('shows the default bar when no window is key, and its items post for no window', function (): void {
    driver()->setDefaultMenuBar(driver()->profile('main'));
    $bar = session()->application()->mainMenu();
    $quit = $bar->itemAtIndex(0)->submenu()->itemAtIndex(2);
    $refresh = $bar->itemAtIndex(1)->submenu()->itemAtIndex(1);

    choose($quit);
    choose($refresh);

    expect(takeMail(session()))->toEqual([new QuitRequested(null), new MenuActivated('', 'view.refresh')]);
});

it('keeps the default bar built when the same profile is set again', function (): void {
    driver()->setDefaultMenuBar(driver()->profile('tools'));
    driver()->setDefaultMenuBar(driver()->profile('main'));
    $bar = session()->application()->mainMenu();

    driver()->setDefaultMenuBar(driver()->profile('main'));

    expect(session()->application()->mainMenu())->toBe($bar)
        ->and($bar->itemAtIndex(1)->submenu()->itemAtIndex(1)->title())->toBe('Refresh');
});
