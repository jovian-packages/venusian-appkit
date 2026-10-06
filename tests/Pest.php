<?php

declare(strict_types=1);

use Jovian\Toolkits\Appkit\Bridge\AppkitBridgeDriver;
use Jovian\Toolkits\Appkit\Bridge\AppkitSession;
use Surface\Bridge\ToolkitManager;
use Surface\Bridge\ToolkitPump;
use Surface\Contracts\Drawing\LentSurface;
use Surface\Contracts\Drawing\SurfaceBorrower;
use Surface\Contracts\Framebuffers\FormatSpec;
use Surface\Contracts\Framebuffers\Framebuffer;
use Surface\Framebuffers\Native\NativeDirtyFramebuffer;
use Surface\Windows\ToolkitWindowManager;
use Voyager\Config\Repository;
use Voyager\Vessel\ControlPanel;

if (! extension_loaded('appkit')) {
    throw new RuntimeException('venusian-appkit tests need ext-appkit loaded.');
}

const TEST_MENUS = [
    'main' => [
        ['label' => 'App', 'items' => [
            ['role' => 'about', 'label' => 'About'],
            ['separator' => true],
            ['role' => 'quit', 'label' => 'Quit', 'hotkey' => 'q'],
        ]],
        ['label' => 'View', 'items' => [
            ['id' => 'grid', 'label' => 'Show Grid', 'toggle' => true, 'on' => false],
            ['id' => 'view.refresh', 'label' => 'Refresh', 'hotkey' => 'R'],
        ]],
    ],
    'tools' => [
        ['label' => 'Tools', 'items' => [
            ['id' => 'tools.measure', 'label' => 'Measure'],
        ]],
    ],
];

/**
 * The one driver for the process: AppKit has one shared application, so every test
 * shares the driver, its session and its windows, through a container like the one
 * the framework hands the bridge.
 */
function driver(): AppkitBridgeDriver
{
    static $driver = null;

    if (is_null($driver)) {
        $container = new ControlPanel();
        $container->registerInstance('config', new Repository([
            'windows' => [
                'about' => ['name' => 'venusian-appkit tests', 'version' => '0.10.0', 'copyright' => null],
                'default_menu' => 'main',
                'menus' => TEST_MENUS,
            ],
        ]));
        $container->registerInstance('toolkit-bridge', $toolkits = new ToolkitManager($container));
        $container->registerInstance('toolkit-windows', new ToolkitWindowManager($toolkits, TEST_MENUS, 'main'));
        $driver = new AppkitBridgeDriver($container);
    }

    return $driver;
}

function session(): AppkitSession
{
    return driver()->connect();
}

/** Mail the session is holding for a loop, taken out. */
function takeMail(AppkitSession $session): array
{
    return (function (): array {
        [$mail, $this->outbox] = [$this->outbox, []];

        return $mail;
    })->call($session);
}

/** Pump AppKit for $seconds through the real sleeper, which flushes latest mail after each pump as the loop does. */
function pumpFor(float $seconds): void
{
    $pump = new ToolkitPump(session());
    $until = microtime(true) + $seconds;
    while (microtime(true) < $until) {
        $pump->sleep(10_000_000);
    }
}

/** Pump through the real sleeper until $until() holds or $seconds pass; whether it held. */
function pumpUntil(callable $until, float $seconds): bool
{
    $pump = new ToolkitPump(session());
    $limit = microtime(true) + $seconds;
    while (! $until()) {
        if (microtime(true) >= $limit) {
            return false;
        }
        $pump->sleep(10_000_000);
    }

    return true;
}

/** Perform a built item as if the user chose it. */
function choose(NSMenuItem $item): void
{
    $menu = $item->menu();
    $menu->performActionForItemAtIndex($menu->indexOfItem($item));
}

/** A borrower that keeps what it was asked to present into; its framebuffer is a plain dirty one. */
final class LayerBorrower implements SurfaceBorrower
{
    /** @var list<LentSurface> */
    public array $presented = [];

    public Framebuffer $frame;

    public function __construct()
    {
        $this->frame = new NativeDirtyFramebuffer(FormatSpec::rgba8(), 8, 8);
    }

    public function framebuffer(): Framebuffer
    {
        return $this->frame;
    }

    public function lendingHandles(): array
    {
        return [];
    }

    public function presentInto(LentSurface $surface): bool
    {
        $this->presented[] = $surface;

        return true;
    }
}
