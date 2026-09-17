---
type: Component
title: AppKitInputEngine
description: >-
  The input.appkit engine: keyboard and mouse from the ext's NSEvent tap,
  gamepads from GameController.framework, behind Surface's InputEngineDriver.
tags: [appkit, input, keyboard, mouse, gamepad, gamecontroller]
status: draft
generated: { by: claude-opus-5/claude-code, at: "2026-09-17T15:00:00Z" }
sources:
  - id: engine
    resource: src/Input/AppKitInputEngine.php
    title: AppKitInputEngine
  - id: keymap
    resource: src/Input/KeyCodeMap.php
    title: KeyCodeMap
  - id: gc
    resource: src/Input/GameControllerSource.php
    title: GameControllerSource
  - id: windows
    resource: src/Input/NativeWindowSpace.php
    title: NativeWindowSpace
  - id: activity
    resource: src/Input/NativeAppActivity.php
    title: NativeAppActivity
  - id: contract
    resource: https://github.com/VenusianPHP/surface/blob/main/src/Surface/Contracts/HumanInput/InputEngineDriver.php
    title: Surface InputEngineDriver
---

# Overview

Provider binds `AppKitInputEngine` singleton, alias `input.appkit`, built
from `ExtInputTap`, `GameControllerSource`, `NativeWindowSpace`,
`NativeAppActivity`. Surface's
`HumanInputManager` resolves the alias; nothing names the class.

Four seams, each an interface so tests run ext-free:

| Seam | Real | Job |
|---|---|---|
| `InputTap` | `ExtInputTap` | `Bridge::watchInput(mask)` / `Bridge::drainInput()` |
| `GamepadSource` | `GameControllerSource` | handles, name, extended?, buttons, axes, connect/disconnect callback |
| `WindowSpace` | `NativeWindowSpace` | windowNumber → `{name, content_width, content_height}`; `setAcceptsMouseMovedEvents(true)` once per window |
| `AppActivity` | `NativeAppActivity` | `onResign(Closure)` via `Bridge::observeNotification(0, 'NSApplicationDidResignActiveNotification')`; `stop()` removes it |

# Lifecycle

- `connect()`: new Keyboard + Mouse, `tap->watch(mask)`, `gamepads->onChange(dirty = true)`, `activity->onResign(release_all = true)`, one gamepad sync.[^engine]
- `poll()`: settle every device → release-all if pending → `windows->acceptMouseMoves()` → `apply(tap->drain())` → resync pads if dirty → read every pad. Never waits.
- `disconnect()`: `tap->watch(0)`, `gamepads->stop()`, `activity->stop()`, devices dropped.
- **Focus loss**: app resigns active → next poll, after settle, releases every down key and every `MouseButton` via `update(false)` (release edges visible that poll) and clears `Modifiers`. Events drained that same poll apply after. Gamepads untouched.[^activity]
- **No pump of its own.** The tap records while `os` pumps NSApp (main thread, inside `Bridge::pump`); `input` ticks after `os`. The monitor returns every event, so windows still receive them.

Mask = OR of `NSEventMask` values: key down/up, flags changed, left/right/other mouse down/up/dragged, mouse moved, scroll wheel.

# Keyboard

- Key identity: macOS virtual key code (`kVK_*`) via `KeyCodeMap`, layout independent; unmapped → `Key::UNKNOWN`.[^keymap] Digit row out of order (`0x16` = 6, `0x17` = 5).
- `KEY_DOWN`: repeat (`isARepeat`) skips only the key `update()`; its text still appends (held "a" + 2 repeats → `aaa`). Text = `characters`, withheld under Command or Control; Option-composed text passes (`ø`); C0 controls, `0x7F`, `U+F700–U+F8FF` (function-key private use) always stripped.
- `FLAGS_CHANGED`: key from `keyCode`; down = that key's device-dependent bit in `modifierFlags` — L-ctrl `0x0001`, L-shift `0x0002`, R-shift `0x0004`, L-cmd `0x0008`, R-cmd `0x0010`, L-alt `0x0020`, R-alt `0x0040`, R-ctrl `0x2000`. Caps lock → `NS_EVENT_MODIFIER_FLAG_CAPS_LOCK`; fn → `NS_EVENT_MODIFIER_FLAG_FUNCTION`. Left and right tracked apart.
- Every key / flags event sets `Modifiers` from the device-independent SHIFT / CONTROL / OPTION / COMMAND flags.
- Missed modifier-up (released while another app was key): every `KEY_DOWN` / `KEY_UP` / `FLAGS_CHANGED` releases any held sided modifier (L/R shift, ctrl, alt, cmd) whose device bit is clear. Releases only; caps lock and fn excluded.

# Mouse

- Window = `windows()[windowNumber]`, read once per `apply()`. Position `x = locationInWindow.x`, `y = content_height − locationInWindow.y` (AppKit is bottom-left; Surface top-left). Unknown window → position untouched.
- `window()` = name only while the point is inside the content, `[0, content_width) × [0, content_height)`; outside (drag past the edge) → null, position still updated.
- `addMotion(deltaX, deltaY)`; AppKit `deltaY` already y-down. Ext reads deltas only on moved / dragged / scroll, so down / up records carry 0.
- `buttonNumber` 0 LEFT, 1 RIGHT, 2 MIDDLE, 3 X1, 4 X2; others ignored. Down on `*_MOUSE_DOWN`, up on `*_MOUSE_UP`.
- `SCROLL_WHEEL` → `addWheel(scrollingDeltaX, scrollingDeltaY)`, ÷10 when `hasPreciseScrollingDeltas` (points → lines). Surface wheel is physical (dy > 0 = rolled away): both deltas negated when `isDirectionInvertedFromDevice` (natural scrolling).
- `NativeWindowSpace` resolves through the container per call: `native-window` driver's `AppKitWindowDelegate`s (`->window`, `->content`) plus `AppKitStagedWindow`s (`->window`, `->attached->view`) when `stages` is bound.[^windows]

# Gamepads

- Device id `gc-<handle>`. Extended profile → Surface `GameController` with all six axes; micro-only → `GamePad`.
- Extended buttons: A SOUTH, B EAST, X WEST, Y NORTH, shoulders, thumbstick buttons LEFT/RIGHT_STICK, Menu START, Options BACK, Home GUIDE, dpad → DPAD_*. Absent element (null box) left out of the button list.[^gc]
- Extended axes: thumbstick x/y → LEFT/RIGHT_X/Y, triggers `value` → LEFT/RIGHT_TRIGGER. GC sticks are y-up; `GameControllerSource::yDown()` negates both Y axes.
- Micro: A SOUTH, X WEST, Menu START, dpad; no axes.
- Controller and element boxes held per attached controller; a read is one ext call per element.
- `onChange()` observes `GCControllerDidConnectNotification` / `GCControllerDidDisconnectNotification` with object 0 and calls `GCController::setShouldMonitorBackgroundEvents(true)`; `stop()` removes both observers and drops held boxes. In a bare CLI process the background flag reads back false after the set.

# Errors

`AppKitInputException extends HumanInputException`; `noSuchController(handle)` when a handle is not a live `GCController`.

[^engine]: `src/Input/AppKitInputEngine.php`
[^keymap]: `src/Input/KeyCodeMap.php`
[^gc]: `src/Input/GameControllerSource.php`
[^windows]: `src/Input/NativeWindowSpace.php`
[^activity]: `src/Input/NativeAppActivity.php`
