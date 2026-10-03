<?php

namespace Jovian\Toolkits\Appkit\Bridge;

use Jovian\Toolkits\Appkit\Contracts\Bridge\AppkitBridgeDriver as BridgeContract;
use Jovian\Toolkits\Appkit\Windows\AppkitMenuBar;
use Jovian\Toolkits\Appkit\Windows\AppkitWindow;
use Surface\Bridge\ToolkitBridgeDriver;
use Surface\Contracts\Windows\Menus\MenuProfile as MenuProfileContract;
use Surface\Contracts\Windows\ToolkitWindowDriver;
use Surface\Contracts\Windows\WindowException;
use Surface\Windows\Menus\MenuProfile;

class AppkitBridgeDriver extends ToolkitBridgeDriver implements BridgeContract, ToolkitWindowDriver
{
    /**
     * Open windows by name; a window leaves when it closes.
     * @var array<string, AppkitWindow>
     */
    protected array $windows = [];

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

    public function connect(): AppkitSession
    {
        $this->session ??= new AppkitSession();

        return $this->session->connect();
    }

    public function open(string $name, int $width, int $height, ?MenuProfileContract $menu = null): AppkitWindow
    {
        if (isset($this->windows[$name])) {
            throw new WindowException("A window named '{$name}' is already open.");
        }

        return $this->windows[$name] = new AppkitWindow($name, $this->connect(), $this, $width, $height, $this->concrete($menu));
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

    public function setDefaultMenuBar(?MenuProfileContract $menu): void
    {
        $menu = $this->concrete($menu);
        // The window manager passes the configured profile on every open: the same profile keeps the bar built.
        if ($menu === $this->default_menu) {
            return;
        }

        $this->default_menu = $menu;
        $this->default_bar = null;
        $this->showDefaultMenuBar();
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

        $this->installDefaultMenuBar();
    }

    /**
     * Install the default bar: with no key window, or for a key window that has no bar of its own.
     * @return void
     */
    public function installDefaultMenuBar(): void
    {
        if (is_null($this->session)) {
            return;
        }

        if (is_null($this->default_menu)) {
            $this->session->application()->setMainMenu(\NSMenu::initWithTitle('MainMenu'));
            return;
        }

        $this->default_bar ??= new AppkitMenuBar($this->session, $this->default_menu, null, $this->about());
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
