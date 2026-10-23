<?php

namespace Jovian\Toolkits\Appkit\Windows;

use Jovian\Toolkits\Appkit\Bridge\AppkitSession;
use NSControlStateValue;
use NSMenu;
use NSEventModifierFlags;
use NSMenuItem;
use ObjCTarget;
use Surface\Contracts\Windows\Mail\MenuActivated;
use Surface\Contracts\Windows\Mail\MenuToggled;
use Surface\Contracts\Windows\Mail\QuitRequested;
use Surface\Contracts\Windows\Menus\MenuRole;
use Surface\Contracts\Windows\WindowException;
use Surface\Windows\Menus\Accelerator;
use Surface\Windows\Menus\MenuItem;
use Surface\Windows\Menus\MenuProfile;
use Surface\Windows\Menus\MenuStates;
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
     * Every node by id: items and folders.
     * @var array<string, NSMenuItem>
     */
    protected array $nodes = [];

    /**
     * Whether each item is an edit role, which keeps its own action.
     * @var array<string, bool>
     */
    protected array $roles = [];

    /** The own and effective state of each node. */
    protected readonly MenuStates $states;

    /** The target of an enabled folder in an autoenabling menu: it does nothing. */
    protected ?ObjCTarget $folder_target = null;

    /** While a child window is key: every item but the edit roles shows as disabled. */
    protected bool $blocked = false;

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
        $this->states = new MenuStates($profile);

        foreach ($profile->folders as $folder) {
            $this->main->addItem($this->folder($folder));
        }
        foreach (array_keys($this->nodes) as $id) {
            $this->apply($id);
        }
    }

    /** The action a folder in an autoenabling menu gets to show as disabled: NSMenuItem, its own target, does not implement it. */
    public const string DISABLED_FOLDER = 'venusianDisabledFolder:';

    /**
     * The key equivalent: the key's character, letters lower case (Shift goes in the mask only).
     */
    public static function keyEquivalent(Accelerator $keys): string
    {
        return match ($keys->key->value) {
            'up' => mb_chr(NSUpArrowFunctionKey), 'down' => mb_chr(NSDownArrowFunctionKey),
            'left' => mb_chr(NSLeftArrowFunctionKey), 'right' => mb_chr(NSRightArrowFunctionKey),
            'home' => mb_chr(NSHomeFunctionKey), 'end' => mb_chr(NSEndFunctionKey),
            'page_up' => mb_chr(NSPageUpFunctionKey), 'page_down' => mb_chr(NSPageDownFunctionKey),
            'delete' => mb_chr(NSDeleteFunctionKey),
            // The Delete key sends U+007F; AppKit matches it to a Backspace key equivalent.
            'backspace' => mb_chr(NSBackspaceCharacter),
            'enter' => mb_chr(NSCarriageReturnCharacter),
            'tab' => mb_chr(NSTabCharacter),
            // No SDK constant.
            'escape' => "\e",
            'space' => ' ',
            'f1' => mb_chr(NSF1FunctionKey), 'f2' => mb_chr(NSF2FunctionKey), 'f3' => mb_chr(NSF3FunctionKey),
            'f4' => mb_chr(NSF4FunctionKey), 'f5' => mb_chr(NSF5FunctionKey), 'f6' => mb_chr(NSF6FunctionKey),
            'f7' => mb_chr(NSF7FunctionKey), 'f8' => mb_chr(NSF8FunctionKey), 'f9' => mb_chr(NSF9FunctionKey),
            'f10' => mb_chr(NSF10FunctionKey), 'f11' => mb_chr(NSF11FunctionKey), 'f12' => mb_chr(NSF12FunctionKey),
            default => (string) array_search($keys->key, Accelerator::CHARACTERS, true),
        };
    }

    /** The modifier mask: Command, Control, Option, Shift. */
    public static function modifierMask(Accelerator $keys): int
    {
        return ($keys->command(true) ? NSEventModifierFlags::COMMAND->value : 0)
            | ($keys->control(true) ? NSEventModifierFlags::CONTROL->value : 0)
            | ($keys->alt ? NSEventModifierFlags::OPTION->value : 0)
            | ($keys->shift ? NSEventModifierFlags::SHIFT->value : 0);
    }

    /**
     * Set the state of a node; the node and the nodes below it show their effective state. Posts nothing.
     * @throws WindowException
     */
    public function setItemEnabled(string $item, bool $enabled): void
    {
        $this->states->set($item, $enabled);
        foreach ($this->states->subtree($item) as $id) {
            $this->apply($id);
        }
    }

    /** @throws WindowException */
    public function isItemEnabled(string $item): bool
    {
        return $this->states->isEnabled($item);
    }

    /**
     * Block the bar while a child window is key, or unblock it: every item but the edit roles
     * shows as disabled, and folders keep their state. The states do not change. Posts nothing.
     */
    public function block(bool $blocked): void
    {
        if ($blocked === $this->blocked) {
            return;
        }
        $this->blocked = $blocked;
        foreach (array_keys($this->nodes) as $id) {
            $this->apply($id);
        }
    }

    /**
     * One built node, item or folder, by its profile id.
     * @throws WindowException
     */
    public function node(string $id): NSMenuItem
    {
        return $this->nodes[$id] ?? throw new WindowException("No node '{$id}' in menu profile '{$this->profile->name}'.");
    }

    /**
     * Show a node's effective state. A menu with autoenable off takes setEnabled. A folder that
     * holds an edit role keeps autoenable on, and AppKit ignores setEnabled there: an item is
     * disabled by a null action, a folder by a target that does not implement its action. An
     * enabled folder there has a target that does nothing: AppKit keeps a folder with no action
     * disabled once it has validated it disabled.
     */
    protected function apply(string $id): void
    {
        $native = $this->nodes[$id];
        $effective = $this->states->effective($id)
            && ! ($this->blocked && ! $native->hasSubmenu() && ! ($this->roles[$id] ?? false));
        if ($native->menu()?->autoenablesItems() !== true) {
            $native->setEnabled($effective);
        } elseif ($native->hasSubmenu()) {
            $this->folder_target ??= new ObjCTarget(static function (): void {});
            $native->setTarget($effective ? $this->folder_target : $native);
            $native->setAction($effective ? ObjCTarget::ACTION : self::DISABLED_FOLDER);
        } elseif (! $this->roles[$id]) {
            $native->setAction($effective ? ObjCTarget::ACTION : null);
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
        $this->nodes[$folder->id] = $slot;
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
            $this->nodes[$item->id] = $native;
            $this->roles[$item->id] = true;

            return $native;
        }

        $native = NSMenuItem::initWithTitleActionKeyEquivalent($item->label, ObjCTarget::ACTION, is_null($item->keys) ? '' : self::keyEquivalent($item->keys));
        if (! is_null($item->keys)) {
            $native->setKeyEquivalentModifierMask(self::modifierMask($item->keys));
        }
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
        $this->nodes[$item->id] = $native;
        $this->roles[$item->id] = false;
        if ($item->toggle) {
            $native->setState($item->on ? NSControlStateValue::ON : NSControlStateValue::OFF);
            $this->toggles[$item->id] = $native;
        }

        return $native;
    }
}
