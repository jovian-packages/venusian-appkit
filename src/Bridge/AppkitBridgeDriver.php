<?php

namespace Jovian\Toolkits\Appkit\Bridge;

use Jovian\Toolkits\Appkit\Contracts\Bridge\AppkitBridgeDriver as BridgeContract;
use Jovian\Toolkits\Appkit\Services\AppkitClipboard;
use Jovian\Toolkits\Appkit\Services\AppkitDesktop;
use Jovian\Toolkits\Appkit\Services\AppkitDialogs;
use Jovian\Toolkits\Appkit\Windows\AppkitDisplays;
use Jovian\Toolkits\Appkit\Windows\AppkitMenuBar;
use Jovian\Toolkits\Appkit\Windows\AppkitStagedWindow;
use Jovian\Toolkits\Appkit\Windows\AppkitWindow;
use Surface\Bridge\ToolkitBridgeDriver;
use Surface\Contracts\Windows\Display;
use Surface\Contracts\Windows\Menus\MenuProfile as MenuProfileContract;
use Surface\Contracts\Windows\StagedWindowDriver;
use Surface\Contracts\Windows\ToolkitWindow;
use Surface\Contracts\Windows\ToolkitWindowDriver;
use Surface\Contracts\Windows\WindowException;
use Surface\Windows\ChildWindows;
use Surface\Windows\Menus\MenuProfile;
use Surface\Windows\Menus\MenuStates;
use Surface\Windows\StagedWindow;

class AppkitBridgeDriver extends ToolkitBridgeDriver implements BridgeContract, ToolkitWindowDriver, StagedWindowDriver
{
    /**
     * Open windows by name; a window leaves when it closes.
     * @var array<string, AppkitWindow>
     */
    protected array $windows = [];

    /**
     * Open staged windows by name; a window leaves when it closes.
     * @var array<string, AppkitStagedWindow>
     */
    protected array $staged = [];

    /**
     * The bar macOS shows while none of our windows is key.
     * @var MenuProfile|null
     */
    protected ?MenuProfile $default_menu = null;

    /**
     * Its built menu, kept while it is installed: AppKit holds menu targets weakly.
     * @var AppkitMenuBar|null
     */
    protected ?AppkitMenuBar $default_bar = null;

    /** The states of the default profile's nodes, kept for a default bar built later. */
    protected ?MenuStates $default_states = null;

    protected ?AppkitDialogs $dialogs = null;

    protected ?AppkitClipboard $clipboard = null;

    protected ?AppkitDesktop $desktop = null;

    public function connect(): AppkitSession
    {
        $this->session ??= new AppkitSession();

        return $this->session->connect();
    }

    /** With a parent, the window is a sheet on it. */
    public function open(string $name, int $width, int $height, ?MenuProfileContract $menu = null, ?ToolkitWindow $parent = null): AppkitWindow
    {
        if (isset($this->windows[$name])) {
            throw new WindowException("A window named '{$name}' is already open.");
        }
        if (! is_null($parent)) {
            ChildWindows::check($this, $parent, $menu);
            $this->dialogs()->refuseChild($parent);
        }

        return $this->windows[$name] = new AppkitWindow($name, $this->connect(), $this, $width, $height, $this->concrete($menu), $parent);
    }

    /** Alerts and file panels, as sheets on their parent. */
    public function dialogs(): AppkitDialogs
    {
        return $this->dialogs ??= new AppkitDialogs($this, $this->connect());
    }

    public function clipboard(): AppkitClipboard
    {
        return $this->clipboard ??= new AppkitClipboard();
    }

    public function desktop(): AppkitDesktop
    {
        return $this->desktop ??= new AppkitDesktop();
    }

    public function has(string $name): bool
    {
        return isset($this->windows[$name]);
    }

    public function get(string $name): ?AppkitWindow
    {
        return $this->windows[$name] ?? null;
    }

    /**
     * @return array<string, AppkitWindow>
     */
    public function all(): array
    {
        return $this->windows;
    }

    public function closeAll(): void
    {
        foreach ($this->windows as $window) {
            $window->close();
        }
    }

    public function openStaged(string $name, int $width, int $height, array $options = []): AppkitStagedWindow
    {
        if (isset($this->staged[$name])) {
            throw new WindowException("A staged window named '{$name}' is already open.");
        }

        return $this->staged[$name] = new AppkitStagedWindow($name, $this->connect(), $this, $width, $height, StagedWindow::options($options, $name));
    }

    public function hasStaged(string $name): bool
    {
        return isset($this->staged[$name]);
    }

    public function getStaged(string $name): ?AppkitStagedWindow
    {
        return $this->staged[$name] ?? null;
    }

    /**
     * @return array<string, AppkitStagedWindow>
     */
    public function allStaged(): array
    {
        return $this->staged;
    }

    public function closeAllStaged(): void
    {
        foreach ($this->staged as $window) {
            $window->close();
        }
    }

    public function displays(): array
    {
        $this->connect();

        return AppkitDisplays::all();
    }

    public function primaryDisplay(): Display
    {
        $this->connect();

        return AppkitDisplays::primary();
    }

    /**
     * Forget a staged window that closed.
     * @param string $name
     * @return void
     */
    public function forgetStaged(string $name): void
    {
        unset($this->staged[$name]);
        $this->showDefaultMenuBar();
    }

    public function setDefaultMenuBar(?MenuProfileContract $menu): void
    {
        $menu = $this->concrete($menu);
        // The window manager passes the configured profile on every open: the same profile keeps the bar built.
        if ($menu === $this->default_menu) {
            return;
        }

        $this->default_menu = $menu;
        $this->default_states = is_null($menu) ? null : new MenuStates($menu);
        $this->default_bar = null;
        $this->showDefaultMenuBar();
    }

    /**
     * Set the state of a node of the default profile; a bar built later starts from it. Posts nothing.
     * @throws WindowException
     */
    public function setItemEnabled(string $item, bool $enabled): void
    {
        $this->defaultStates()->set($item, $enabled);
        $this->default_bar?->setItemEnabled($item, $enabled);
    }

    /** @throws WindowException */
    public function isItemEnabled(string $item): bool
    {
        return $this->defaultStates()->isEnabled($item);
    }

    protected function defaultStates(): MenuStates
    {
        return $this->default_states ?? throw new WindowException('No default menu bar profile is set.');
    }

    /**
     * Install the default bar unless one of our windows is key: macOS has one bar for the app.
     * @return void
     */
    public function showDefaultMenuBar(): void
    {
        foreach ($this->windows as $window) {
            if ($window->isKey()) {
                return;
            }
        }

        // A key staged window shows the default bar too: it has none of its own.
        $this->installDefaultMenuBar();
    }

    /**
     * Install the default bar: with no key window, or for a key window that has no bar of its own.
     * Blocked (a child window is key), every item but the edit roles shows as disabled.
     * @param bool $blocked
     * @return void
     */
    public function installDefaultMenuBar(bool $blocked = false): void
    {
        if (is_null($this->session)) {
            return;
        }

        if (is_null($this->default_menu)) {
            $this->session->application()->setMainMenu(\NSMenu::initWithTitle('MainMenu'));
            return;
        }

        if (is_null($this->default_bar)) {
            $this->default_bar = new AppkitMenuBar($this->session, $this->default_menu, null, $this->about());
            foreach ($this->default_states->changes() as $id => $enabled) {
                $this->default_bar->setItemEnabled($id, $enabled);
            }
        }
        $this->default_bar->block($blocked);
        $this->default_bar->install();
    }

    /**
     * Forget a window that closed.
     * @param string $name
     * @return void
     */
    public function forget(string $name): void
    {
        unset($this->windows[$name]);
        $this->showDefaultMenuBar();
    }

    /**
     * A profile from config/windows.php, through the window manager.
     * @param string $name
     * @return MenuProfile
     * @throws WindowException When no profile is registered under that name.
     */
    public function profile(string $name): MenuProfile
    {
        return $this->app->get('toolkit-windows')->profile($name);
    }

    /**
     * The About identity from config('windows.about').
     * @return array{name?: string|null, version?: string|null, copyright?: string|null}
     */
    public function about(): array
    {
        return (array) $this->app->get('config')->get('windows.about', []);
    }

    /**
     * @param MenuProfileContract|null $menu
     * @return MenuProfile|null
     * @throws WindowException When the profile is not one Surface parsed.
     */
    protected function concrete(?MenuProfileContract $menu): ?MenuProfile
    {
        if (is_null($menu) || $menu instanceof MenuProfile) {
            return $menu;
        }

        throw new WindowException(get_class($menu).' is not a menu profile parsed by Surface\Windows\Menus\MenuProfile.');
    }
}
