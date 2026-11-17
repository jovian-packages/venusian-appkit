<?php

namespace Jovian\Toolkits\Appkit\Input;

use CFRunLoop;
use Closure;
use Surface\Contracts\HumanInput\GamepadAxis;
use Surface\Contracts\HumanInput\GamepadButton;
use Surface\Contracts\HumanInput\HumanInputException;
use Surface\HumanInput\Devices\GameController;
use Surface\HumanInput\Devices\GamePad;
use Surface\HumanInput\PadSource;

/**
 * The 'gamecontroller' pad source: GameController.framework's pads, read by polling, as
 * `gc-<hash>` devices every stage reads. An extended gamepad is a game controller with the buttons
 * it has and all six axes; a micro gamepad is a game pad. No hardware id (the framework exposes
 * none that survives a reconnect); player indexes 0 to 3 light the pad's LEDs.
 *
 * GameController updates values on the main run loop: with no toolkit session connected, poll()
 * runs it for an instant first. Its input reaches the active app only, unless macOS grants
 * background events, asked for on the first connect notification or listed pad (SDL found asking
 * earlier crashes macOS 12.3). macOS 15.4.x refuses them: with a session, pads follow focus as keys
 * do; with none the process never becomes active, so pad reads say so and name 'sdl3', which reads
 * pads through IOKit HID whatever app is active.
 */
final class GameControllerPadSource extends PadSource
{
    private bool $connected = false;

    private bool $asked = false;

    private ?bool $background = null;

    private bool $notified = false;

    private bool $resigned = false;

    /** Released for focus loss: readings wait until the app is active again. */
    private bool $at_rest = false;

    /**
     * @param Closure(): bool $sessions whether any toolkit session is connected now: one pumps the main run loop and can make the process active
     *
     * @throws HumanInputException When AppKit is not here.
     */
    public function __construct(
        private readonly Closure $sessions,
        private readonly GameControllers $controllers = new SystemGameControllers(),
    ) {
        if (! extension_loaded('appkit')) {
            throw new HumanInputException('ext-appkit is not loaded.');
        }
    }

    public function name(): string
    {
        return 'gamecontroller';
    }

    /** The macOS version, as SystemVersion.plist states it; 'unknown' when it cannot be read. */
    public static function macosVersion(string $plist = '/System/Library/CoreServices/SystemVersion.plist'): string
    {
        $xml = is_readable($plist) ? (string) file_get_contents($plist) : '';

        return preg_match('#<key>ProductVersion</key>\s*<string>([^<]+)</string>#', $xml, $version) === 1 ? $version[1] : 'unknown';
    }

    public function connect(): static
    {
        if ($this->connected) {
            return $this;
        }

        $this->controllers->watch(function (): void {
            $this->notified = true;
        }, function (): void {
            $this->resigned = true;
        });
        $this->connected = true;

        return $this;
    }

    public function disconnect(): void
    {
        if (! $this->connected) {
            return;
        }

        $this->controllers->unwatch();
        foreach (array_keys($this->pads) as $id) {
            $this->remove($id);
        }
        [$this->notified, $this->resigned, $this->at_rest, $this->connected] = [false, false, false, false];
    }

    public function connected(): bool
    {
        return $this->connected;
    }

    public function poll(): void
    {
        if (! $this->connected) {
            return;
        }

        $session = ($this->sessions)();
        if (! $session) {
            CFRunLoop::runInMode(kCFRunLoopDefaultMode, 0.0, true);
        }

        $readings = $this->controllers->read();
        if (! $this->asked && ($this->notified || $readings !== [])) {
            $this->asked = true;
            $this->background = $this->controllers->monitorBackground();
        }

        $listed = [];
        foreach ($readings as $reading) {
            $listed[$reading->id] = $reading;
            if (! isset($this->pads[$reading->id])) {
                $this->add($this->device($reading));
            }
        }
        foreach (array_keys(array_diff_key($this->pads, $listed)) as $gone) {
            $this->remove($gone);
        }

        if ($this->background === false && $session && ! $this->following()) {
            return;
        }
        $this->resigned = false;

        foreach ($listed as $id => $reading) {
            $device = $this->pads[$id];
            foreach ($reading->buttons as $button => $down) {
                $device->update(GamepadButton::from($button), $down);
            }
            if ($device instanceof GameController) {
                foreach ($reading->axes as $axis => $value) {
                    $device->setAxis(GamepadAxis::from($axis), $value);
                }
            }
        }
    }

    /**
     * @throws HumanInputException macOS refuses pad input to a process that cannot become active.
     */
    public function pads(): array
    {
        if ($this->background === false && ! ($this->sessions)()) {
            throw new HumanInputException(sprintf(
                "gamecontroller: macOS %s gives game pads only to the active app (GCController.shouldMonitorBackgroundEvents reads back false: a 15.4 regression Apple fixed in 15.5), and a process with no window never becomes active. Set human-input.pads to 'sdl3', which reads pads through IOKit HID whatever app is active, or update macOS.",
                self::macosVersion(),
            ));
        }

        return parent::pads();
    }

    /** Whether macOS grants pad input while the app is not active; null until a pad connected. */
    public function backgroundMonitoring(): ?bool
    {
        return $this->background;
    }

    /**
     * Pads follow focus where background events are refused: the app resigning releases every
     * button and centres every axis, and readings wait until it is active again (GameController
     * freezes values meanwhile, so a held button would come back down). Whether to read now.
     */
    private function following(): bool
    {
        if ($this->resigned) {
            $this->resigned = false;
            $this->at_rest = true;
            foreach ($this->pads as $device) {
                $device->releaseAll();
            }
        }
        if ($this->at_rest && ! $this->controllers->active()) {
            return false;
        }
        $this->at_rest = false;

        return true;
    }

    private function device(GcReading $reading): GamePad
    {
        $buttons = array_map(GamepadButton::from(...), array_keys($reading->buttons));
        if ($reading->axes === []) {
            return new GamePad($reading->id, $reading->name, $buttons, null, $reading->lights);
        }

        $controller = new GameController($reading->id, $reading->name, $buttons, null, $reading->lights);
        // every axis it has reads from the start, centred
        foreach (array_keys($reading->axes) as $axis) {
            $controller->setAxis(GamepadAxis::from($axis), 0.0);
        }

        return $controller;
    }
}
