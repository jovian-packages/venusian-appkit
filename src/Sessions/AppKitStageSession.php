<?php

namespace Jovian\Venusian\AppKit\Sessions;

use Jovian\Bindings\AppKit\Enums\NSAutoresizingMaskOptions;
use Jovian\Bindings\AppKit\Enums\NSBackingStoreType;
use Jovian\Bindings\AppKit\Enums\NSWindowStyleMask;
use Jovian\Bindings\AppKit\NS\NSWindow;
use Jovian\Bindings\AppKit\Runtime\Delegate;
use Jovian\Bindings\AppKit\Values\NSRect;
use Jovian\Venusian\AppKit\Exceptions\AppKitStageException;
use Jovian\Venusian\AppKit\Exceptions\AppKitWindowException;
use Jovian\Venusian\AppKit\Stages\AppKitStagedWindow;
use Jovian\Venusian\AppKit\Views\AttachesAppKitEngines;
use Surface\Contracts\Drawing\GPUEngineDriver;
use Surface\Contracts\Stage\StageException;
use Surface\Contracts\Stage\StageHost;
use Surface\Stage\StagedWindow;
use Surface\Stage\StageSession;
use Throwable;

/**
 * The appkit stage host. It rides the native bridge session: the same
 * NSApplication, the same pump (sharesNativePump), so native windows and
 * AppKit stages live in one process with one event loop. Stages are whole
 * NSWindows whose content view is the engine's surface. Every mint failure
 * is a StageException, so a sketch catches that alone: AppKit's own failures
 * are AppKitStageException, an engine's attach() failure is wrapped as
 * AppKitStageException::attachFailed (engine exception as previous), and a
 * StageException the engine raised passes through unwrapped.
 */
final class AppKitStageSession extends StageSession
{
    use AttachesAppKitEngines;

    public function __construct(private readonly BridgedMacOSSession $bridge) {}

    public function host(): StageHost
    {
        return StageHost::APPKIT;
    }

    public function sharesNativePump(): bool
    {
        return true;
    }

    /** The bridge session initialised AppKit when the container built it. */
    protected function initializeEngine(): void {}

    protected function connectToEngine(): void
    {
        $this->bridge->connect();
    }

    /** The native bridge outlives its stages; nothing to withdraw here. */
    protected function disconnectEngine(): void {}

    protected function pumpEngine(int $budget_ms): int
    {
        return $this->bridge->pump($budget_ms);
    }

    protected function mintStage(string $name, GPUEngineDriver $engine, int $width, int $height): StagedWindow
    {
        $style = NSWindowStyleMask::TITLED->value
            | NSWindowStyleMask::CLOSABLE->value
            | NSWindowStyleMask::MINIATURIZABLE->value
            | NSWindowStyleMask::RESIZABLE->value;
        $window = NSWindow::initWithContentRectStyleMaskBackingDefer(
            new NSRect(0.0, 0.0, (float) $width, (float) $height),
            $style,
            NSBackingStoreType::NS_BACKING_STORE_BUFFERED,
            false,
        );
        if (! $window instanceof NSWindow) {
            throw AppKitStageException::windowMintFailed($name);
        }
        // The registry owns the handle; AppKit must not also release on close.
        $window->setReleasedWhenClosed(false);

        $scale = $window->backingScaleFactor();
        // Any attach failure closes the never-shown window, then surfaces as a stage error.
        try {
            $attached = $this->attachEngine($name, $engine, $scale, $width, $height);
        } catch (Throwable $e) {
            $window->close();
            throw match (true) {
                $e instanceof StageException => $e,
                $e instanceof AppKitWindowException => AppKitStageException::viewMintFailed($name, $e),
                default => AppKitStageException::attachFailed(StageHost::APPKIT, $engine->engine(), $e),
            };
        }
        if (is_null($attached)) {
            $window->close();
            throw AppKitStageException::unsupported(StageHost::APPKIT, $engine->engine(), $engine->surfaceKind());
        }

        // A failure while wiring the attached surface into the window releases the executor and closes the window.
        try {
            $attached->view->setAutoresizingMask(
                NSAutoresizingMaskOptions::NS_VIEW_WIDTH_SIZABLE->value | NSAutoresizingMaskOptions::NS_VIEW_HEIGHT_SIZABLE->value,
            );
            $window->setContentView($attached->view->handle);
            $window->center();

            $delegate = new Delegate('NSWindowDelegate');
            $stage = new AppKitStagedWindow($name, $engine->engine(), $attached->attachment->executor, $width, $height, $scale, $window, $attached, $delegate);

            // Close asks, never closes: the stage mails stage.closed.<name> and the sketch decides.
            $delegate->on('windowShouldClose:', function (mixed ...$args) use ($stage): bool {
                $stage->closeRequested();

                return false;
            });
            $delegate->on('windowDidResize:', function (mixed ...$args) use ($stage): void {
                $stage->nativeResized();
            });
            $window->setDelegate($delegate->handle());
        } catch (Throwable $e) {
            try {
                $attached->attachment->executor->release();
            } catch (Throwable) {
                // The wiring failure is the one worth reporting.
            } finally {
                $window->close();
            }

            throw $e instanceof StageException ? $e : AppKitStageException::windowSetupFailed($name, $e);
        }

        return $stage;
    }

    /** A stage's no-layer error is a StageException, like every other stage failure. */
    protected function noLayerError(string $name, string $engine): Throwable
    {
        return AppKitStageException::engineReturnedNoLayer($name, $engine);
    }
}
