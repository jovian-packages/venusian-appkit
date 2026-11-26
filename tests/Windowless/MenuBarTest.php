<?php

declare(strict_types=1);

use Jovian\Toolkits\Appkit\Appkit;
use Jovian\Toolkits\Appkit\AppkitAdapterDriver;
use Jovian\Toolkits\Appkit\AppkitSession;
use Jovian\Toolkits\Appkit\AppkitWindow;
use Jovian\Toolkits\Appkit\Menus\AppkitShortcut;
use Surface\Toolkits\Managers\MenuManager;
use Surface\Toolkits\Menus\About;
use Surface\Toolkits\Menus\AboutPanel;
use Surface\Toolkits\Menus\Choice;
use Surface\Toolkits\Menus\Item;
use Surface\Toolkits\Menus\Menu;
use Surface\Toolkits\Menus\MenuBar;
use Surface\Toolkits\Menus\Separator;
use Surface\Toolkits\Menus\Shortcut;
use Surface\Toolkits\Menus\Standard;
use Surface\Toolkits\Menus\Toggle;
use Surface\Toolkits\Menus\Runtime\MenuNodeActivated;
use Surface\Toolkits\ToolkitSession;
use Surface\Contracts\Toolkits\Menus\ShortcutKey;

/*
| The menu bar on real NSMenus, NSMenuItems and NSEvents, and nothing on screen: the session's
| application is never activated and no window is presented. So no window is key here. What
| needs one (AppKit routing a real key press, the About panel) is checked by running an app.
*/

/** An Appkit that never becomes an app on screen. */
class QuietAppkit extends Appkit
{
    public function connect(): void {}

    public function disconnect(): void {}
}

/** @return array{MenuManager, AppkitAdapterDriver, AppkitSession} */
function quietMenus(?MenuBar $default = null): array
{
    static $toolkit = null;
    $toolkit ??= (new QuietAppkit())->setDelegate()->finishLaunching();

    $windows = is_null($default) ? [] : ['default_menu' => 'app', 'menus' => ['app' => $default]];
    $app = bootCore(['toolkits' => ['windows' => $windows]]);

    $session = new AppkitSession($app);
    Closure::bind(function () use ($toolkit): void {
        [$this->application, $this->initialized, $this->connected] = [$toolkit, true, true];
    }, $session, ToolkitSession::class)();

    $driver = new AppkitAdapterDriver($app);
    Closure::bind(function () use ($session): void {
        $this->session = $session;
    }, $driver, AppkitAdapterDriver::class)();

    return [$app->make(MenuManager::class), $driver, $session];
}

function quietWindow(array $menus, ?MenuBar $bar = null, string $name = 'main'): AppkitWindow
{
    [$manager, $driver] = $menus;
    /** @var AppkitWindow $window */
    $window = $driver->open($name, 400, 300);
    $manager->opened($window, $driver, $bar);
    becomeKey($window);

    return $window;
}

/** What AppKit's delegate callback runs. */
function becomeKey(AppkitWindow $window): void
{
    (fn () => $this->becameKey())->call($window);
}

function resignKey(AppkitWindow $window): void
{
    (fn () => $this->resignedKey())->call($window);
}

function mainMenu(): NSMenu
{
    return NSApplication::sharedApplication()->mainMenu();
}

/** The item at a path of titles in the visible bar; '#0' is by index. */
function nativeItem(string ...$titles): NSMenuItem
{
    $menu = mainMenu();
    $found = null;
    foreach ($titles as $title) {
        $found = null;
        for ($i = 0; $i < $menu->numberOfItems(); $i++) {
            if ($title === "#{$i}" || $menu->itemAtIndex($i)->title() === $title) {
                $found = $menu->itemAtIndex($i);
                break;
            }
        }
        if (is_null($found)) {
            throw new RuntimeException("No native menu item '{$title}'.");
        }
        $menu = $found->submenu() ?? $menu;
    }

    return $found;
}

/** @return list<string> */
function titlesIn(NSMenu $menu): array
{
    $titles = [];
    for ($i = 0; $i < $menu->numberOfItems(); $i++) {
        $item = $menu->itemAtIndex($i);
        $titles[] = $item->isSeparatorItem() ? '-' : $item->title();
    }

    return $titles;
}

function chooseItem(NSMenuItem $item): void
{
    $menu = $item->menu();
    $menu->update();
    $menu->performActionForItemAtIndex($menu->indexOfItem($item));
}

function showsEnabled(NSMenuItem $item): bool
{
    $item->menu()->update();

    return $item->isEnabled();
}

/** @return list<object> what was posted, each node chosen delivered as the loop would */
function delivered(array $menus): array
{
    [$manager, , $session] = $menus;
    $take = fn (): array => Closure::bind(function (): array {
        [$mail, $this->outbox] = [$this->outbox, []];

        return $mail;
    }, $session, ToolkitSession::class)();

    foreach ($take() as $mail) {
        if ($mail instanceof MenuNodeActivated) {
            $manager->deliver($mail);
        }
    }

    return $take();
}

/** Where each key is on an ANSI keyboard. */
function keyCodeOf(ShortcutKey $key): int
{
    return [
        'A' => 0, 'S' => 1, 'D' => 2, 'F' => 3, 'H' => 4, 'G' => 5, 'Z' => 6, 'X' => 7, 'C' => 8, 'V' => 9,
        'B' => 11, 'Q' => 12, 'W' => 13, 'E' => 14, 'R' => 15, 'Y' => 16, 'T' => 17, '1' => 18, '2' => 19,
        '3' => 20, '4' => 21, '6' => 22, '5' => 23, '=' => 24, '9' => 25, '7' => 26, '-' => 27, '8' => 28,
        '0' => 29, ']' => 30, 'O' => 31, 'U' => 32, '[' => 33, 'I' => 34, 'P' => 35, 'Enter' => 36, 'L' => 37,
        'J' => 38, "'" => 39, 'K' => 40, ';' => 41, '\\' => 42, ',' => 43, '/' => 44, 'N' => 45, 'M' => 46,
        '.' => 47, 'Tab' => 48, 'Space' => 49, '`' => 50, 'Backspace' => 51, 'Escape' => 53,
        'F5' => 96, 'F6' => 97, 'F7' => 98, 'F3' => 99, 'F8' => 100, 'F9' => 101, 'F11' => 103, 'F10' => 109,
        'F12' => 111, 'Home' => 115, 'PageUp' => 116, 'Delete' => 117, 'F4' => 118, 'End' => 119, 'F2' => 120,
        'PageDown' => 121, 'F1' => 122, 'Left' => 123, 'Right' => 124, 'Down' => 125, 'Up' => 126,
    ][$key->value];
}

/**
 * A key press as macOS makes it. One rebuilt for a $window is for routing only: AppKit matches
 * key equivalents on the original's key, which the rebuilt one has lost.
 */
function keyPress(Shortcut $shortcut, int $window = 0): NSEvent
{
    $code = keyCodeOf($shortcut->key);
    $flags = AppkitShortcut::mask($shortcut);
    if ($code >= 96) {
        $flags |= NSEventModifierFlags::FUNCTION->value | ($code >= 123 ? NSEventModifierFlags::NUMERIC_PAD->value : 0);
    }

    $pressed = CGEvent::createKeyboardEvent(null, $code, true);
    $pressed->setFlags($flags);
    $read = NSEvent::eventWithCGEvent($pressed);
    if ($window === 0) {
        return $read;
    }

    return NSEvent::keyEventWithTypeLocationModifierFlagsTimestampWindowNumberContextCharactersCharactersIgnoringModifiersIsARepeatKeyCode(
        NSEventType::KEY_DOWN, new NSPoint(0.0, 0.0), $flags, 0.0, $window, null,
        (string) $read->characters(), (string) $read->charactersIgnoringModifiers(), false, $code,
    );
}

function keysOf(AppkitWindow $window): object
{
    $mounted = (fn () => $this->windows[$window->name()]->mounter)->call(app(MenuManager::class));

    return (fn () => $this->keys)->call($mounted->bar);
}

afterEach(function (): void {
    foreach ($this->opened ?? [] as $window) {
        $window->close();
    }
});

it('builds the tree, the application menu first', function (): void {
    $menus = quietMenus();
    $this->opened[] = quietWindow($menus, MenuBar::make(
        Menu::make('File', 'file',
            Item::make('Open', id: 'open'),
            Separator::make(),
            Menu::make('Export', 'export', Item::make('PDF', id: 'pdf')),
            Choice::make(['s' => 'Small', 'l' => 'Large'], 'l', id: 'size'),
            Toggle::make('Wrap', true, id: 'wrap'),
        ),
        Menu::application(Standard::quit()),
    ));

    expect(mainMenu()->numberOfItems())->toBe(2)
        ->and(titlesIn(nativeItem('#1')->submenu()))->toBe(['Open', '-', 'Export', 'Small', 'Large', 'Wrap'])
        ->and(titlesIn(nativeItem('File', 'Export')->submenu()))->toBe(['PDF'])
        ->and(nativeItem('File', 'Small')->state())->toBe(NSControlStateValue::OFF)
        ->and(nativeItem('File', 'Large')->state())->toBe(NSControlStateValue::ON)
        ->and(nativeItem('File', 'Wrap')->state())->toBe(NSControlStateValue::ON);
});

it('posts an item\'s mail, after its callback', function (): void {
    $menus = quietMenus();
    $ran = 0;
    $this->opened[] = quietWindow($menus, MenuBar::make(Menu::make('File', 'file',
        Item::make('Save', id: 'save', onClick: function () use (&$ran): void { $ran++; }),
    )));
    delivered($menus);

    chooseItem(nativeItem('File', 'Save'));
    expect($ran)->toBe(0);    // never inside AppKit's callback

    $mail = delivered($menus);
    expect($ran)->toBe(1)->and($mail[0]->name())->toBe('menu.clicked.file.save')->and($mail[0]->window)->toBe('main');
});

it('moves a toggle\'s and a choice\'s mark at once', function (): void {
    $menus = quietMenus();
    $this->opened[] = quietWindow($menus, MenuBar::make(Menu::make('View', 'view',
        Toggle::make('Wrap', false, id: 'wrap'),
        Choice::make(['1' => 'One', '2' => 'Two'], '1', id: 'zoom'),
    )));
    delivered($menus);

    chooseItem(nativeItem('View', 'Wrap'));
    chooseItem(nativeItem('View', 'Two'));

    expect(nativeItem('View', 'Wrap')->state())->toBe(NSControlStateValue::ON)
        ->and(nativeItem('View', 'One')->state())->toBe(NSControlStateValue::OFF)
        ->and(nativeItem('View', 'Two')->state())->toBe(NSControlStateValue::ON);

    $mail = delivered($menus);
    expect([$mail[0]->name(), $mail[0]->on, $mail[1]->name(), $mail[1]->option])
        ->toBe(['menu.toggled.view.wrap', true, 'menu.chosen.view.zoom', '2']);
});

it('asks to quit and quits nothing', function (): void {
    $menus = quietMenus();
    $this->opened[] = quietWindow($menus, MenuBar::make(Menu::application(Standard::quit())));
    delivered($menus);

    chooseItem(nativeItem('#0')->submenu()->itemAtIndex(0));

    expect(delivered($menus)[0]->name())->toBe('quit.requested');
});

it('gives the standard kinds AppKit\'s own actions, which their receivers answer', function (): void {
    $menus = quietMenus();
    $this->opened[] = $window = quietWindow($menus, MenuBar::make(
        Menu::application(Standard::hide(), Standard::hideOthers(), Standard::showAll()),
        Standard::windowMenu(),
        Standard::editMenu(),
    ));
    $area = NSTextView::scrollableTextView()->documentView();
    $application = NSApplication::sharedApplication();

    foreach ([
        [nativeItem('#0')->submenu(), $application, 3],
        [nativeItem('#1')->submenu(), $window->native(), 4],
        [nativeItem('#2')->submenu(), $area, 7],
    ] as [$menu, $receiver, $count]) {
        for ($i = 0; $i < $count; $i++) {
            $item = $menu->itemAtIndex($i);
            if ($item->isSeparatorItem() || in_array($item->action(), ['undo:', 'redo:'], true)) {
                continue;    // the text view's undo manager answers those two
            }
            expect($item->target())->toBeNull()
                ->and($receiver->respondsToSelector($item->action()))->toBeTrue($item->action());
        }
    }

    expect(nativeItem('Edit', '#0')->action())->toBe('undo:')
        ->and(nativeItem('Edit', '#1')->action())->toBe('redo:');
});

it('edits the focused text input with the edit items\' actions', function (): void {
    $menus = quietMenus();
    $this->opened[] = $window = quietWindow($menus, MenuBar::make(Standard::editMenu()));
    $field = NSTextField::textFieldWithString('hello');
    $window->native()->contentView()->addSubview($field);
    $window->native()->makeFirstResponder($field);
    $editor = $window->native()->firstResponder();
    $editor->setSelectedRange(new NSRange(0, 0));

    // Not cut, copy or paste: those would replace what is on the clipboard.
    expect($field->sendActionTo(nativeItem('Edit', 'Select All')->action(), $editor))->toBeTrue()
        ->and($editor->selectedRange()->length)->toBe(5);
});

it('gives Paste and Match Style the text view\'s plain-text paste, which a text view answers', function (): void {
    $menus = quietMenus();
    $this->opened[] = quietWindow($menus, MenuBar::make(Menu::make('Edit', 'edit', Standard::pasteAndMatchStyle())));
    $item = nativeItem('Edit', '#0');
    $area = NSTextView::scrollableTextView()->documentView();

    expect($item->action())->toBe('pasteAsPlainText:')
        ->and($item->target())->toBeNull()
        ->and($area->respondsToSelector($item->action()))->toBeTrue();
});

it('lets a standard item given an id report instead', function (): void {
    $menus = quietMenus();
    $this->opened[] = quietWindow($menus, MenuBar::make(
        Menu::application(About::item(id: 'about')),
        Menu::make('Edit it', 'edit', Standard::copy()->id('copy')),
    ));
    delivered($menus);

    chooseItem(nativeItem('#0')->submenu()->itemAtIndex(0));
    chooseItem(nativeItem('Edit it', 'Copy'));

    expect(array_map(fn (object $mail): string => $mail->name(), delivered($menus)))
        ->toBe(['menu.clicked.app.about', 'menu.clicked.edit.copy']);
});

it('shows enabled and disabled, menus inside menus too', function (): void {
    $menus = quietMenus();
    $bar = fn (bool $on): MenuBar => MenuBar::make(
        Menu::make('File', 'file',
            Item::make('Save', id: 'save')->enabled($on),
            Menu::make('Export', 'export', Item::make('PDF', id: 'pdf'))->enabled($on),
            Standard::copy()->enabled($on),
        ),
        Menu::make('Tools', 'tools', Item::make('Run', id: 'run'))->enabled($on),
    );
    $this->opened[] = quietWindow($menus, $bar(false));

    $state = fn (): array => [
        showsEnabled(nativeItem('File', 'Save')),
        showsEnabled(nativeItem('File', 'Export')),
        showsEnabled(nativeItem('File', 'Export', 'PDF')),
        nativeItem('Tools')->isEnabled(),
        showsEnabled(nativeItem('Tools', 'Run')),
    ];
    expect($state())->toBe([false, false, false, false, false])
        ->and(nativeItem('File', 'Copy')->action())->toBeNull();

    $menus[0]->window('main', $bar(true));
    expect($state())->toBe([true, true, true, true, true])
        ->and(nativeItem('File', 'Copy')->action())->toBe('copy:');

    $menus[0]->window('main', $bar(false));
    expect($state())->toBe([false, false, false, false, false]);
});

it('changes in place: label, order, what is added and removed, shortcut', function (): void {
    $menus = quietMenus();
    $this->opened[] = quietWindow($menus, MenuBar::make(Menu::make('List', 'list',
        Item::make('One', id: 'one'),
        Item::make('Two', id: 'two'),
        Choice::make(['a' => 'A', 'b' => 'B'], 'a', id: 'letter'),
        Item::make('Three', id: 'three'),
    )));
    $kept = nativeItem('List', 'One');

    $menus[0]->window('main', MenuBar::make(Menu::make('List', 'list',
        Choice::make(['a' => 'A', 'b' => 'B'], 'a', id: 'letter'),
        Item::make('New', id: 'new'),
        Item::make('Three', id: 'three'),
        Item::make('First', id: 'one')->shortcut(Shortcut::primary('J')),
    )));

    $now = nativeItem('List', 'First');
    expect(titlesIn(nativeItem('List')->submenu()))->toBe(['A', 'B', 'New', 'Three', 'First'])
        ->and($now->isEqual($kept))->toBeTrue()
        ->and($now->keyEquivalent())->toBe('j')
        ->and($now->keyEquivalentModifierMask())->toBe(NSEventModifierFlags::COMMAND->value);
});

it('keeps its place above what macOS adds to Edit', function (): void {
    $menus = quietMenus();
    $bar = MenuBar::make(Menu::make('Edit', 'edit', Standard::copy()));
    $this->opened[] = quietWindow($menus, $bar);
    nativeItem('Edit')->submenu()->update();

    $menus[0]->window('main', $bar->extend('edit', fn (Menu $edit) => $edit->append(Item::make('Find', id: 'find'))));

    expect(array_slice(titlesIn(nativeItem('Edit')->submenu()), 0, 2))->toBe(['Copy', 'Find']);
});

it('fires a shortcut from a real key event', function (ShortcutKey $key, bool $shift): void {
    $menus = quietMenus();
    $shortcut = Shortcut::primary($key)->shift($shift);
    $fired = false;
    $this->opened[] = quietWindow($menus, MenuBar::make(Menu::make('Keys', 'keys',
        Item::make('Go', onClick: function () use (&$fired): void { $fired = true; })->shortcut($shortcut),
    )));
    delivered($menus);

    expect(mainMenu()->performKeyEquivalent(keyPress($shortcut)))->toBeTrue();
    delivered($menus);
    expect($fired)->toBeTrue();
})->with(function (): Generator {
    foreach (ShortcutKey::cases() as $key) {
        yield "Cmd+{$key->value}" => [$key, false];
        // Never made, not even in process: macOS keeps Cmd+Shift+Q (Log Out) and Cmd+Shift+/ (Help).
        if ($key !== ShortcutKey::Q && $key !== ShortcutKey::SLASH) {
            yield "Cmd+Shift+{$key->value}" => [$key, true];
        }
    }
});

it('holds a bare key from the menu until no widget has used it', function (ShortcutKey $key, bool $shift): void {
    $menus = quietMenus();
    $shortcut = Shortcut::key($key)->shift($shift);
    $fired = false;
    $this->opened[] = $window = quietWindow($menus, MenuBar::make(Menu::make('Keys', 'keys',
        Item::make('Go', onClick: function () use (&$fired): void { $fired = true; })->shortcut($shortcut),
    )));
    $window->native()->makeFirstResponder(null);
    delivered($menus);
    $event = keyPress($shortcut, $window->native()->windowNumber());
    $keys = keysOf($window);

    expect($keys->pressed($event))->toBeTrue();
    delivered($menus);
    expect($fired)->toBeFalse()
        ->and(nativeItem('Keys', 'Go')->keyEquivalent())->toBe(AppkitShortcut::equivalent($shortcut));

    expect($keys->unused(keyPress($shortcut)))->toBeTrue();    // what the window's keyDown: runs
    delivered($menus);
    expect($fired)->toBeTrue();
})->with(function (): Generator {
    foreach (ShortcutKey::cases() as $key) {
        if ($key->standsAlone()) {
            yield $key->value => [$key, false];
            yield "Shift+{$key->value}" => [$key, true];
        }
    }
});

it('gives a text input the keys text uses and the menu the others', function (): void {
    $menus = quietMenus();
    $this->opened[] = $window = quietWindow($menus, MenuBar::make(Menu::make('Keys', 'keys', Item::make('Go', id: 'go'))));
    $field = NSTextField::textFieldWithString('abc');
    $window->native()->contentView()->addSubview($field);
    $window->native()->makeFirstResponder($field);
    $keys = keysOf($window);
    $first = fn (string $written): bool => $keys->focusFirst(keyPress(Shortcut::of($written)));

    foreach (['Delete', 'Backspace', 'Left', 'Shift+Right', 'Up', 'Down', 'Home', 'End', 'PageUp', 'PageDown', 'F5'] as $used) {
        expect($first($used))->toBeTrue($used);
    }
    foreach (['F1', 'F6', 'F12', 'Shift+F5'] as $unused) {
        expect($first($unused))->toBeFalse($unused);
    }
});

it('leaves keys with a modifier, and another window\'s, to AppKit', function (): void {
    $menus = quietMenus();
    $this->opened[] = $window = quietWindow($menus, MenuBar::make(Menu::make('Keys', 'keys',
        Item::make('Bare', id: 'bare')->shortcut(Shortcut::key('Delete')),
        Item::make('Held', id: 'held')->shortcut(Shortcut::primary('Delete')),
    )));
    $number = $window->native()->windowNumber();
    $keys = keysOf($window);

    expect($keys->pressed(keyPress(Shortcut::primary('Delete'), $number)))->toBeFalse()
        ->and($keys->pressed(keyPress(Shortcut::key('Home'), $number)))->toBeFalse()
        ->and($keys->pressed(keyPress(Shortcut::key('Delete'), $number + 999)))->toBeFalse();
});

it('follows the key window and shows the default bar when none is key', function (): void {
    $menus = quietMenus(MenuBar::make(Menu::make('Default', 'default')));
    $this->opened[] = $one = quietWindow($menus, MenuBar::make(Menu::make('One', 'one')), 'one');
    $this->opened[] = $two = quietWindow($menus, MenuBar::make(Menu::make('Two', 'two')), 'two');

    expect(nativeItem('#1')->title())->toBe('Two');

    resignKey($two);
    expect(nativeItem('#1')->title())->toBe('Default');

    becomeKey($one);
    expect(nativeItem('#1')->title())->toBe('One');

    resignKey($two);    // late, after the other became key
    expect(nativeItem('#1')->title())->toBe('One');

    $one->close();
    expect(nativeItem('#1')->title())->toBe('Default');
});

it('does not grow as bars are swapped', function (): void {
    $menus = quietMenus(MenuBar::make(Standard::editMenu(), Standard::windowMenu()));
    $this->opened[] = $window = quietWindow($menus, MenuBar::make(Standard::editMenu(), Standard::windowMenu()));
    $count = function (): array {
        NSApplication::sharedApplication()->updateWindows();

        return [count(titlesIn(nativeItem('Edit')->submenu())), count(titlesIn(nativeItem('Window')->submenu()))];
    };
    $swap = function () use ($window): void {
        resignKey($window);
        becomeKey($window);
    };
    $swap();
    $settled = $count();

    for ($i = 0; $i < 20; $i++) {
        $swap();
    }

    expect($count())->toBe($settled)
        ->and(array_slice(titlesIn(nativeItem('Edit')->submenu()), 0, 3))->toBe(['Undo', 'Redo', '-']);
});

it('fills the About panel with the fields given, and none of PHP\'s', function (): void {
    $options = AppkitSession::aboutOptions(new AboutPanel('My App', '1.2', '2026 Me', 'Thanks'));

    expect([$options['ApplicationName'], $options['ApplicationVersion'], $options['Version'], $options['Copyright'], $options['Credits']->string()])
        ->toBe(['My App', '1.2', '', '2026 Me', 'Thanks'])
        ->and(AppkitSession::aboutOptions(new AboutPanel('My App')))
        ->toBe(['ApplicationName' => 'My App', 'ApplicationVersion' => '', 'Version' => '', 'Copyright' => '']);
});

it('never put anything on screen', function (): void {
    $application = NSApplication::sharedApplication();

    expect($application->isActive())->toBeFalse()
        ->and($application->keyWindow())->toBeNull();
});
