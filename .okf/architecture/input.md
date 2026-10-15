---
type: Module
title: Input
description: AppkitInputEngine and GameControllerPadSource, HumanInput's appkit engine and gamecontroller pad source.
resource: src/Input/AppkitInputEngine.php
tags: [appkit, human-input, gamecontroller]
status: draft
generated: { by: claude-opus/5.5, at: 2026-10-09T00:00:00Z }
sources:
  - id: engine
    resource: src/Input/AppkitInputEngine.php
    title: AppkitInputEngine
  - id: pads
    resource: src/Input/GameControllerPadSource.php
    title: GameControllerPadSource
---

# Engine

* **Tap.** `connect()` taps the driver's connected `AppkitSession` or throws. The pump shows each event to taps before `sendEvent()` (or before withholding it for a context menu). `see()` copies a `SeenEvent`; `poll()` applies the copies to a Surface `Keyboard` and `Mouse`.
* **Which window.** A window is ours when it is in the driver's `all()` or `allStaged()`, matched by `windowNumber()`. Keys and flags need the event's window to be ours; buttons and motion that window or the one under the pointer; the wheel the one under the pointer.
* **The pointer.** It goes into the window under the screen point (`NSWindow::windowNumberAtPoint…`), so a non-key window reads right. `poll()` follows the pointer location (a closure, `NSEvent::mouseLocation()` by default) first, then the events.
* **Keys** by position through Surface's `MacKeycodes`. FLAGS_CHANGED reads each side from its device-dependent bit (IOLLEvent.h), fn from the Function flag. Caps Lock is one press.
* **Losing focus.** `NSApplicationDidResignActiveNotification` releases everything at the next poll.
* **Releases the session never sees.** AppKit's tracking loops (NSButtonCell trackMouse:, NSMenu popUp) dequeue their own events, so each poll releases buttons whose `NSEvent::pressedMouseButtons()` bit is clear and keys `CGEventSourceKeyState` (combined session) reads up, through closures tests set. A key-up or a modifier's release over a window not ours applies when the key is held here.
* **The wheel** as SDL's Cocoa code reads it: `-deltaX`, `deltaY`, mouse ticks rounded away from zero, inverted direction negated.
* **Moved events** are turned on for each of our windows and off at disconnect.

# Pads

* **Reading.** `GameControllerPadSource` reads `GameControllers` (`SystemGameControllers` over `GCController::controllers()` and `GcReading::of()`) each poll. Ids are `gc-<hash>`. An extended gamepad is a GameController with the buttons it has and six axes (y negated to Surface's down-positive); a micro gamepad is a GamePad. Player indexes 0–3 set `GCControllerPlayerIndex`.
* **Watching.** `connect()` starts wireless discovery and observes the connect and resign notifications; `disconnect()` stops both.
* **Run loop.** With no toolkit session connected, `poll()` runs `CFRunLoop::runInMode(kCFRunLoopDefaultMode, 0.0, true)` first: GameController updates values on the main run loop.
* **Background events.** Asked for on the first connect notification or listed pad; `backgroundMonitoring()` reports the readback. Refused (macOS 15.4.x): with a session, resign releases pads and readings wait for `isActive()`; with none, pad reads throw naming `'sdl3'`, and HumanInput's `poll()` goes on.

Registered by `VenusianAppkitServiceProvider::input()`: `extend('appkit', …)`, `extendPads('gamecontroller', …)`.
