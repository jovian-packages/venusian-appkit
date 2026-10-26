<?php

declare(strict_types=1);

use Surface\Contracts\Windows\Mail\MenuActivated;
use Surface\Contracts\Windows\Mail\MenuRadioChosen;
use Surface\Contracts\Windows\Mail\MenuToggled;
use Surface\Contracts\Windows\Mail\QuitRequested;
use Surface\Contracts\Windows\WindowException;
use Jovian\Toolkits\Appkit\Windows\AppkitMenuBar;
use Surface\Windows\Menus\Accelerator;

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
        ->and($bar->item('view.refresh')->keyEquivalent())->toBe(mb_chr(NSF5FunctionKey))
        ->and($bar->item('view.refresh')->keyEquivalentModifierMask())->toBe(NSEventModifierFlags::COMMAND->value | NSEventModifierFlags::SHIFT->value)
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

it('gives each accelerator key its key equivalent', function (string $keys, string $equivalent): void {
    expect(AppkitMenuBar::keyEquivalent(Accelerator::parse($keys)))->toBe($equivalent);
})->with([
    'letter, lower case' => ['Primary+Shift+A', 'a'],
    'digit' => ['Primary+5', '5'],
    'punctuation' => ['Primary+]', ']'],
    'grave' => ['Primary+`', '`'],
    'F1' => ['F1', mb_chr(0xF704)],
    'F12' => ['F12', mb_chr(0xF70F)],
    'up' => ['Alt+UP', mb_chr(0xF700)],
    'down' => ['Alt+DOWN', mb_chr(0xF701)],
    'left' => ['Alt+LEFT', mb_chr(0xF702)],
    'right' => ['Alt+RIGHT', mb_chr(0xF703)],
    'home' => ['Primary+HOME', mb_chr(0xF729)],
    'end' => ['Primary+END', mb_chr(0xF72B)],
    'page up' => ['Primary+PAGE_UP', mb_chr(0xF72C)],
    'page down' => ['Primary+PAGE_DOWN', mb_chr(0xF72D)],
    'delete' => ['Primary+DELETE', mb_chr(0xF728)],
    'backspace' => ['Primary+BACKSPACE', "\x08"],
    'enter' => ['Primary+ENTER', "\r"],
    'tab' => ['Primary+TAB', "\t"],
    'escape' => ['Primary+ESCAPE', "\e"],
    'space' => ['Primary+SPACE', ' '],
]);

it('gives each modifier its mask bit', function (): void {
    expect(AppkitMenuBar::modifierMask(Accelerator::parse('Primary+Ctrl+Alt+Shift+X')))
        ->toBe(NSEventModifierFlags::COMMAND->value | NSEventModifierFlags::CONTROL->value | NSEventModifierFlags::OPTION->value | NSEventModifierFlags::SHIFT->value)
        ->and(AppkitMenuBar::modifierMask(Accelerator::parse('Meta+X')))->toBe(NSEventModifierFlags::COMMAND->value)
        ->and(AppkitMenuBar::modifierMask(Accelerator::parse('F5')))->toBe(0);
});

it('builds a node with enabled false disabled, and the nodes below it', function (): void {
    $bar = driver()->open('main', 320, 200, driver()->profile('main'))->menuBar();

    expect($bar->node('view.locked')->isEnabled())->toBeFalse()
        ->and($bar->node('view.locked.item')->isEnabled())->toBeFalse()
        ->and($bar->node('view.refresh')->isEnabled())->toBeTrue();
});

it('disables a folder and the nodes below it, and restores each own state when it is enabled again', function (): void {
    $window = driver()->open('main', 320, 200, driver()->profile('main'));
    $bar = $window->menuBar();

    expect($window->setItemEnabled('view', false))->toBe($window)
        ->and($bar->node('view')->isEnabled())->toBeFalse()
        ->and($bar->node('view.refresh')->isEnabled())->toBeFalse()
        ->and($bar->node('view.locked.item')->isEnabled())->toBeFalse()
        ->and($window->isItemEnabled('view'))->toBeFalse()
        ->and($window->isItemEnabled('view.refresh'))->toBeTrue();

    $window->setItemEnabled('view', true);

    expect($bar->node('view.refresh')->isEnabled())->toBeTrue()
        ->and($bar->node('view.locked.item')->isEnabled())->toBeFalse()
        ->and(takeMail(session()))->toBe([]);
});

it('chooses items from key presses, and nothing for a disabled item or an item below a disabled folder', function (): void {
    $window = driver()->open('main', 320, 200, driver()->profile('main'));
    $menu = $window->menuBar()->menu();
    $command = NSEventModifierFlags::COMMAND->value;
    $refresh = fn (): NSEvent => keyDown(96, $command | NSEventModifierFlags::SHIFT->value | NSEventModifierFlags::FUNCTION->value);
    $next = fn (): NSEvent => keyDown(125, NSEventModifierFlags::OPTION->value | NSEventModifierFlags::FUNCTION->value);

    $chosen = [$menu->performKeyEquivalent($refresh()), $menu->performKeyEquivalent($next()), $menu->performKeyEquivalent(keyDown(30, $command)), $menu->performKeyEquivalent(keyDown(51, $command))];
    $fired = takeMail(session());
    $window->setItemEnabled('view.refresh', false);
    $off = $menu->performKeyEquivalent($refresh());
    $window->setItemEnabled('view.refresh', true);
    $window->setItemEnabled('view', false);
    $folder = $menu->performKeyEquivalent($next());

    expect($chosen)->toBe([true, true, true, true])
        ->and($fired)->toEqual([new MenuActivated('main', 'view.refresh'), new MenuActivated('main', 'view.next'), new MenuActivated('main', 'view.tab'), new MenuActivated('main', 'view.erase')])
        ->and($off)->toBeFalse()
        ->and($folder)->toBeFalse()
        ->and(takeMail(session()))->toBe([]);
});

it('refuses an unknown id, an edit role and the first folder', function (): void {
    $window = driver()->open('main', 320, 200, driver()->profile('editing'));

    expect(fn () => $window->setItemEnabled('nope', false))->toThrow(WindowException::class, "'nope'")
        ->and(fn () => $window->setItemEnabled('edit.copy', false))->toThrow(WindowException::class, "'Copy'")
        ->and(fn () => $window->setItemEnabled('edit', false))->toThrow(WindowException::class, "'Edit'")
        ->and(fn () => $window->setItemEnabled('app', false))->toThrow(WindowException::class, "'App'")
        ->and(fn () => driver()->open('bare', 320, 200)->setItemEnabled('view', false))->toThrow(WindowException::class, 'no menu bar');
});

it('starts a new bar from its own profile', function (): void {
    $window = driver()->open('main', 320, 200, driver()->profile('main'));
    $window->setItemEnabled('view.refresh', false);

    $window->setMenuBar('main');

    expect($window->isItemEnabled('view.refresh'))->toBeTrue()
        ->and($window->menuBar()->node('view.refresh')->isEnabled())->toBeTrue();
});

it('keeps a state of the default bar set before the bar is built', function (): void {
    driver()->setDefaultMenuBar(null);
    $tools = driver()->open('tools', 320, 200, driver()->profile('tools'))->present();
    expect(pumpUntil(fn () => $tools->isKey(), 5.0))->toBeTrue();
    driver()->setDefaultMenuBar(driver()->profile('main'));

    driver()->setItemEnabled('view.refresh', false);
    driver()->closeAll();
    pumpFor(0.05);
    driver()->showDefaultMenuBar();
    $refresh = session()->application()->mainMenu()->itemAtIndex(1)->submenu()->itemAtIndex(1);

    expect(driver()->isItemEnabled('view.refresh'))->toBeFalse()
        ->and($refresh->title())->toBe('Refresh')
        ->and($refresh->isEnabled())->toBeFalse()
        ->and(fn () => (function (): void {
            driver()->setDefaultMenuBar(null);
            driver()->setItemEnabled('view.refresh', false);
        })())->toThrow(WindowException::class, 'default');
});

function caseStates(AppkitMenuBar $bar): array
{
    return array_map(fn (string $id): bool => $bar->item($id)->state() === NSControlStateValue::ON, ['case.none' => 'case.none', 'case.unimportant' => 'case.unimportant', 'case.all' => 'case.all']);
}

it('sets a radio group\'s item on and the rest off, posting nothing', function (): void {
    $window = driver()->open('main', 320, 200, driver()->profile('choices'));

    expect(caseStates($window->menuBar()))->toBe(['case.none' => true, 'case.unimportant' => false, 'case.all' => false]);

    $window->setRadio('ignore-case', 'case.all');

    expect(caseStates($window->menuBar()))->toBe(['case.none' => false, 'case.unimportant' => false, 'case.all' => true])
        ->and($window->radio('ignore-case'))->toBe('case.all')
        ->and(takeMail(session()))->toBe([])
        ->and(fn () => $window->setRadio('ignore-case', 'compare.wrap'))->toThrow(WindowException::class, "'compare.wrap' is not in radio group 'ignore-case'.")
        ->and(fn () => $window->setRadio('nope', 'case.all'))->toThrow(WindowException::class, "No radio group 'nope'");
});

it('posts MenuRadioChosen for the user\'s choice, and nothing for the item already on', function (): void {
    $window = driver()->open('main', 320, 200, driver()->profile('choices'));

    choose($window->menuBar()->item('case.unimportant'));
    choose($window->menuBar()->item('case.unimportant'));

    expect(takeMail(session()))->toEqual([new MenuRadioChosen('main', 'ignore-case', 'case.unimportant')])
        ->and($window->radio('ignore-case'))->toBe('case.unimportant')
        ->and(caseStates($window->menuBar()))->toBe(['case.none' => false, 'case.unimportant' => true, 'case.all' => false]);
});

it('disables one radio item on its own, and relabels an item and a folder', function (): void {
    $window = driver()->open('main', 320, 200, driver()->profile('choices'));

    $window->setItemEnabled('case.all', false);
    $window->setItemLabel('case.none', 'Nothing');
    $window->setItemLabel('compare', 'Diff');

    expect($window->menuBar()->node('case.all')->isEnabled())->toBeFalse()
        ->and($window->menuBar()->node('case.none')->isEnabled())->toBeTrue()
        ->and($window->menuBar()->item('case.none')->title())->toBe('Nothing')
        ->and($window->itemLabel('case.none'))->toBe('Nothing')
        ->and($window->menuBar()->node('compare')->title())->toBe('Diff')
        ->and($window->menuBar()->node('compare')->submenu()->title())->toBe('Diff')
        ->and(takeMail(session()))->toBe([]);
});

it('keeps the default bar\'s radios, toggles and labels through a window\'s own bar', function (): void {
    driver()->setDefaultMenuBar(driver()->profile('choices'));
    driver()->setRadio('ignore-case', 'case.all');
    driver()->setToggle('compare.wrap', true);
    driver()->setItemLabel('compare.wrap', 'Wrap Lines');
    $tools = driver()->open('tools', 320, 200, driver()->profile('tools'))->present();
    pumpUntil(fn () => $tools->isKey(), 5.0);
    driver()->closeAll();
    pumpFor(0.05);
    driver()->showDefaultMenuBar();
    $compare = session()->application()->mainMenu()->itemAtIndex(1)->submenu();

    expect(driver()->radio('ignore-case'))->toBe('case.all')
        ->and(driver()->isToggled('compare.wrap'))->toBeTrue()
        ->and(driver()->itemLabel('compare.wrap'))->toBe('Wrap Lines')
        ->and($compare->itemAtIndex(2)->state())->toBe(NSControlStateValue::ON)
        ->and($compare->itemAtIndex(0)->state())->toBe(NSControlStateValue::OFF)
        ->and($compare->itemAtIndex(4)->title())->toBe('Wrap Lines')
        ->and($compare->itemAtIndex(4)->state())->toBe(NSControlStateValue::ON);

    choose($compare->itemAtIndex(1));
    expect(driver()->radio('ignore-case'))->toBe('case.unimportant')
        ->and(array_values(array_filter(takeMail(session()), fn (object $mail): bool => $mail instanceof MenuRadioChosen)))->toEqual([new MenuRadioChosen('', 'ignore-case', 'case.unimportant')]);
    driver()->setDefaultMenuBar(driver()->profile('main'));
});
