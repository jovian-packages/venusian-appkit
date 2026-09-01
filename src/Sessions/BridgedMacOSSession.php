<?php

namespace Jovian\Venusian\AppKit\Sessions;

use Jovian\Bindings\AppKit\Enums\NSBackingStoreType;
use Jovian\Bindings\AppKit\Enums\NSWindowStyleMask;
use Jovian\Bindings\AppKit\NS\NSView;
use Jovian\Bindings\AppKit\NS\NSWindow;
use Jovian\Bindings\AppKit\Values\NSRect;
use Jovian\Venusian\AppKit\Exceptions\AppKitWindowException;
use Jovian\Venusian\AppKit\Windows\AppKitWindowDelegate;
use Surface\Bridge\BridgedOSSession;
use Jovian\Bindings\AppKit\Runtime\Bridge;
use Jovian\Bindings\AppKit\NS\NSApplication;
use Jovian\Venusian\AppKit\Exceptions\AppKitBridgeException;
use Jovian\Bindings\AppKit\Enums\NSApplicationActivationPolicy;
use Surface\Contracts\Bridge\MacOSBridge;

/**
 * Surface's bridge to AppKit.
 *
 * Initialisation finishes launching the shared NSApplication, which is the part
 * that cannot be repeated. Connection is activation policy, which is live and
 * reversible — REGULAR puts a Dock icon up, PROHIBITED takes it back down.
 *
 * There is no teardown beyond that. Releasing an NSWindow after NSApp has gone
 * crashes, so jovian/appkit deliberately leaks at process exit and this session
 * does not fight it.
 */
class BridgedMacOSSession extends BridgedOSSession implements MacOSBridge
{
    /**
     * The shared application, held from initialization until the process ends
     * @var NSApplication|null
     */
    protected ?NSApplication $mac_app = null;

    /**
     * Take hold of the shared NSApplication and finish launching it.
     * @return void
     * @throws AppKitBridgeException When AppKit hands back something that is not an application.
     */
    protected function initializeEngine(): void
    {
        $app = NSApplication::sharedApplication();

        if (! $app instanceof NSApplication) {
            throw AppKitBridgeException::unknownNSApplicationResponse();
        }

        $this->mac_app = $app;
        $this->mac_app->finishLaunching();
    }

    /**
     * Become a regular app and take focus, which is what raises the Dock icon.
     * @return void
     */
    protected function connectToEngine(): void
    {
        $this->mac_app->setActivationPolicy(
            activationPolicy: NSApplicationActivationPolicy::REGULAR
        );
        $this->mac_app->activate();
    }

    /**
     * Drop out of the Dock and the app switcher, leaving the app running headless.
     * @return void
     */
    protected function disconnectEngine(): void
    {
        $this->mac_app->setActivationPolicy(
            activationPolicy: NSApplicationActivationPolicy::PROHIBITED
        );
    }

    /**
     * Advance AppKit's run loop, converting Surface's milliseconds to the seconds it wants.
     * @param int $budget_ms Milliseconds the engine may spend. Zero drains without waiting.
     * @return int Events AppKit dispatched.
     */
    protected function pumpEngine(int $budget_ms): int
    {
        return Bridge::pump($budget_ms / 1000.0);
    }

    public function provisionNewWindow(string $name, int $width, int $height): AppKitWindowDelegate
    {
        $rect = new NSRect(0.0, 0.0, $width, $height);
        $style = NSWindowStyleMask::TITLED->value
            | NSWindowStyleMask::CLOSABLE->value
            | NSWindowStyleMask::MINIATURIZABLE->value
            | NSWindowStyleMask::RESIZABLE->value;
        $backing_store = NSBackingStoreType::NS_BACKING_STORE_BUFFERED;

        $window = NSWindow::initWithContentRectStyleMaskBackingDefer($rect, $style, $backing_store, false);

        if (! $window instanceof NSWindow) {
            throw AppKitWindowException::couldNotMint($name);
        }

        $content = $window->contentView();
        if (! $content instanceof NSView) {
            throw AppKitWindowException::noContentView($name);
        }
        return new AppKitWindowDelegate($name, $window, $content);
    }

}
