<?php

namespace Jovian\Toolkits\Appkit\Windows;

use Jovian\Toolkits\Appkit\Bridge\AppkitSession;
use NSControlStateValue;
use NSMenu;
use NSMenuItem;
use ObjCTarget;
use Surface\Contracts\Windows\Mail\MenuActivated;
use Surface\Contracts\Windows\Mail\MenuToggled;
use Surface\Contracts\Windows\Mail\QuitRequested;
use Surface\Contracts\Windows\Menus\MenuRole;
use Surface\Contracts\Windows\WindowException;
use Surface\Windows\Menus\MenuItem;
use Surface\Windows\Menus\MenuProfile;

/**
 * A menu profile built once into an NSMenu tree. macOS has one bar per app, so the window
 * that is key installs its own; the first folder is the app menu, which AppKit titles with
 * the process name. Every item posts mail but the edit roles, which AppKit sends to the first
 * responder; About shows the standard panel.
 */
class AppkitMenuBar
{
    /** AppKit's own action for each edit role, sent to the first responder, and its key equivalent (upper case adds Shift). */
    public const array EDIT = [
        'undo' => ['undo:', 'z'],
        'redo' => ['redo:', 'Z'],
        'cut' => ['cut:', 'x'],
        'copy' => ['copy:', 'c'],
        'paste' => ['paste:', 'v'],
        'select-all' => ['selectAll:', 'a'],
    ];

    protected NSMenu $main;

    /**
     * Every actionable item by id.
     * @var array<string, NSMenuItem>
     */
    protected array $items = [];

    /**
     * Toggle items by id.
     * @var array<string, NSMenuItem>
     */
    protected array $toggles = [];

    /**
     * Kept alive here: NSMenuItem holds its target weakly.
     * @var list<ObjCTarget>
     */
    protected array $targets = [];

    /**
     * @param AppkitSession $session
     * @param MenuProfile $profile
     * @param string|null $window the window it belongs to; null for the app's default bar, whose items post '' (Quit posts null)
     * @param array{name?: string|null, version?: string|null, copyright?: string|null} $about
     */
    public function __construct(
        protected readonly AppkitSession $session,
        protected readonly MenuProfile $profile,
        protected readonly ?string $window,
        protected readonly array $about,
    ) {
        $this->main = NSMenu::initWithTitle('MainMenu');
        $this->main->setAutoenablesItems(false);

        foreach ($profile->folders as $folder) {
            $this->main->addItem($this->folder($folder));
        }
    }

    public function install(): void
    {
        $this->session->application()->setMainMenu($this->main);
    }

    public function setToggle(string $item, bool $on): void
    {
        $this->toggle($item)->setState($on ? NSControlStateValue::ON : NSControlStateValue::OFF);
    }

    public function isToggled(string $item): bool
    {
        return $this->toggle($item)->state() === NSControlStateValue::ON;
    }

    /**
     * The built bar.
     * @return NSMenu
     */
    public function menu(): NSMenu
    {
        return $this->main;
    }

    /**
     * One built item, by its profile id.
     * @param string $id
     * @return NSMenuItem
     * @throws WindowException
     */
    public function item(string $id): NSMenuItem
    {
        return $this->items[$id] ?? throw new WindowException("No item '{$id}' in menu profile '{$this->profile->name}'.");
    }

    protected function toggle(string $item): NSMenuItem
    {
        return $this->toggles[$item] ?? throw new WindowException("No toggle item '{$item}' in menu profile '{$this->profile->name}'.");
    }

    protected function folder(MenuItem $folder): NSMenuItem
    {
        $slot = NSMenuItem::initWithTitleActionKeyEquivalent($folder->label, null, '');
        $menu = NSMenu::initWithTitle($folder->label);
        // A folder with edit items validates them against the first responder; others stay as built.
        $menu->setAutoenablesItems(array_any($folder->items, fn (MenuItem $item): bool => $item->role?->isEdit() === true));

        foreach ($folder->items as $item) {
            $menu->addItem(match (true) {
                $item->separator => NSMenuItem::separatorItem(),
                $item->isFolder() => $this->folder($item),
                default => $this->leaf($item),
            });
        }

        $slot->setSubmenu($menu);

        return $slot;
    }

    protected function leaf(MenuItem $item): NSMenuItem
    {
        if ($item->role?->isEdit() === true) {
            [$action, $key] = self::EDIT[$item->role->value];
            // No target: AppKit sends the action up the responder chain from the first responder.
            $native = NSMenuItem::initWithTitleActionKeyEquivalent($item->label, $action, $key);
            $this->items[$item->id] = $native;

            return $native;
        }

        $native = NSMenuItem::initWithTitleActionKeyEquivalent($item->label, ObjCTarget::ACTION, strtolower($item->hotkey ?? ''));
        $window = $this->window ?? '';

        $target = new ObjCTarget(match (true) {
            $item->role === MenuRole::ABOUT => fn () => $this->session->showAbout($this->about),
            $item->role === MenuRole::QUIT => fn () => $this->session->post(new QuitRequested($this->window)),
            $item->toggle => function () use ($native, $item, $window): void {
                $on = $native->state() !== NSControlStateValue::ON;
                $native->setState($on ? NSControlStateValue::ON : NSControlStateValue::OFF);
                $this->session->post(new MenuToggled($window, $item->id, $on));
            },
            default => fn () => $this->session->post(new MenuActivated($window, $item->id)),
        });

        $native->setTarget($target);
        $this->targets[] = $target;
        $this->items[$item->id] = $native;

        if ($item->toggle) {
            $native->setState($item->on ? NSControlStateValue::ON : NSControlStateValue::OFF);
            $this->toggles[$item->id] = $native;
        }

        return $native;
    }
}
