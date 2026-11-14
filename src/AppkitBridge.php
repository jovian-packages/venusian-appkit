<?php

namespace Jovian\Toolkits\Appkit;

use Surface\Toolkits\ToolkitBridge;
use Surface\Toolkits\Windows\ChildWindows;
use Surface\Contracts\Toolkits\ToolkitWindow;
use Surface\Contracts\Toolkits\Menus\MenuProfile;
use Jovian\Toolkits\Appkit\Services\AppkitDialogs;
use Jovian\Toolkits\Appkit\Services\AppkitClipboard;
use Jovian\Toolkits\Appkit\Services\AppkitDesktop;
use Jovian\Toolkits\Appkit\Services\AppkitSystemAccent;
use Jovian\Toolkits\Appkit\Primitives\Concerns\AppkitFonts;
use Surface\Contracts\Toolkits\Styling\FontMetrics;
use Surface\Contracts\Toolkits\Styling\FontSpec;
use Surface\Contracts\Toolkits\ToolkitRuntimeException;
use Jovian\Toolkits\Appkit\Contracts\AppkitBridgeDriver;
use Surface\Contracts\Toolkits\Menus\MenuProfile as MenuProfileContract;

class AppkitBridge extends ToolkitBridge implements AppkitBridgeDriver
{
    /**
     * Open windows by name; a window leaves when it closes.
     * @var array<string, AppkitWindow>
     */
    protected array $windows = [];

    protected string $session_class = AppkitSession::class;

    protected ?AppkitDialogs $dialogs = null;

    protected ?AppkitClipboard $clipboard = null;

    protected ?AppkitDesktop $desktop = null;

    protected ?AppkitSystemAccent $systemAccent = null;

    public function open(string $name, int $width, int $height, ?MenuProfile $menu = null, ?ToolkitWindow $parent = null): AppkitWindow
    {
        if (isset($this->windows[$name])) {
            throw ToolkitRuntimeException::instanceOpen($name);
        }

        if (! is_null($parent)) {
            ChildWindows::check($this, $parent, $menu);
            $this->dialogs()->refuseChild($parent);
        }

        return $this->windows[$name] = new AppkitWindow($name, $this->connect(), $this, $width, $height, $this->concrete($menu), $parent);
    }

    public function dialogs(): AppkitDialogs
    {
        return $this->dialogs ??= new AppkitDialogs($this, $this->connect());
    }

    public function connect(): AppkitSession
    {
        $this->session ??= new AppkitSession();

        return $this->session->connect();
    }

    /** The general pasteboard; its change count is compared when a window becomes key. */
    public function clipboard(): AppkitClipboard
    {
        return $this->clipboard ??= new AppkitClipboard($this->connect());
    }

    public function desktop(): AppkitDesktop
    {
        return $this->desktop ??= new AppkitDesktop();
    }

    public function systemAccent(): AppkitSystemAccent
    {
        return $this->systemAccent ??= new AppkitSystemAccent();
    }

    public function fontMetrics(FontSpec $font): FontMetrics
    {
        return AppkitFonts::metrics($font);
    }

    public function closeAll(): void
    {
        foreach ($this->windows as $window) {
            $window->close();
        }
    }

    /** @return array<string, AppkitWindow> */
    public function all(): array
    {
        return $this->windows;
    }

    /** A window closed: it leaves the open windows. */
    public function forget(string $name): void
    {
        unset($this->windows[$name]);
    }

    /**
     * A profile from config/windows.php, through the window manager.
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

    public function has(string $name): bool
    {
        return isset($this->windows[$name]);
    }

    public function get(string $name): ?AppkitWindow
    {
        return $this->windows[$name] ?? null;
    }

    /**
     * @param MenuProfileContract|null $menu
     * @return MenuProfile|null
     * @throws ToolkitRuntimeException When the profile is not one Surface parsed.
     */
    protected function concrete(?MenuProfileContract $menu): ?MenuProfile
    {
        if (is_null($menu) || $menu instanceof MenuProfile) {
            return $menu;
        }

        throw new ToolkitRuntimeException(get_class($menu).' is not a menu profile parsed by Surface\Windows\Menus\MenuProfile.');
    }
}