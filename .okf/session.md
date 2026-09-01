---
type: Component
title: BridgedMacOSSession
description: >-
  The AppKit side of Surface's bridge: finishLaunching once, activation
  policy as the cyclable connection, a run loop pumped in seconds, and the
  NSWindow factory Surface cannot own.
tags: [appkit, bridge, session, macos]
status: draft
generated: { by: claude-opus-5/cursor, at: "2026-08-29T21:37:00Z" }
sources:
  - id: session
    resource: src/Sessions/BridgedMacOSSession.php
    title: BridgedMacOSSession
  - id: delegate
    resource: src/Windows/AppKitWindowDelegate.php
    title: AppKitWindowDelegate
  - id: provider
    resource: src/Providers/VenusianAppKitServiceProvider.php
    title: VenusianAppKitServiceProvider
  - id: appkit-runtime
    resource: https://github.com/jovian/appkit/blob/main/.okf/runtime.md
    title: jovian/appkit runtime — ObjCObject, identity map, Bridge
---

# Overview

`BridgedMacOSSession` extends Surface's abstract session and fills its four
hooks.[^session] Surface owns the state machine — idempotency, the drain on
disconnect, the disconnected `pump()` answering zero — so this class holds
only AppKit specifics.

The service provider binds it as a singleton behind the container alias
`mac.bridge`, which is the only name Surface looks for.[^provider]

# The four hooks

| Hook | AppKit |
|---|---|
| `initializeEngine()` | `NSApplication::sharedApplication()`, then `finishLaunching()` |
| `connectToEngine()` | `setActivationPolicy(REGULAR)`, then `activate()` |
| `disconnectEngine()` | `setActivationPolicy(PROHIBITED)` |
| `pumpEngine(int $ms)` | `Bridge::pump($ms / 1000.0)` |

The shared `NSApplication` is held on the session from initialisation until
the process ends.

# Why the split lands here

`finishLaunching()` is the unrepeatable part, so it sits in
`initializeEngine()` behind Surface's `$initialized` flag and runs once at
construction. Activation policy is live and reversible, so it is the
connection: `REGULAR` plus `activate()` raises a Dock icon with no window
open, and `PROHIBITED` drops it again while the app keeps running headless.

This is the whole reason Surface's disconnect means something rather than
being bookkeeping. On Linux the equivalent hooks are no-ops.

# Minting windows

`provisionNewWindow()` is the fifth verb on Surface's contract and the one
piece of it with no shared policy behind it. Surface cannot own it — an
`NSWindow` constructor is AppKit — so the session is the factory.[^session]

| Step | Call |
|---|---|
| Frame | `new NSRect(0.0, 0.0, $width, $height)` |
| Traits | `TITLED \| CLOSABLE \| MINIATURIZABLE \| RESIZABLE` OR'd into the style mask |
| Backing | `NS_BACKING_STORE_BUFFERED`, `defer: false` |
| Wrap | `AppKitWindowDelegate($name, $window, $window->contentView())` |

Two guards, both raising `AppKitWindowException` (a Surface
`WindowableException`): the constructor answering something that is not an
`NSWindow`, and a missing `contentView`.

**`setReleasedWhenClosed(false)` is not optional.** The delegate sets it at
construction. AppKit would otherwise free the window on close while
`jovian/appkit`'s registry still holds the handle, and the next touch is a
use-after-free.[^delegate] The consequence is that a user closing the window
merely hides it — `isVisible()` goes false, the handle stays good — which is
the opposite of GTK, where close destroys.

The origin is `(0, 0)` in AppKit's **bottom-left** coordinate space, so a
minted window lands at the bottom-left of the screen. `center()` exists on
the delegate and nothing calls it.

# Units

Surface's budget is integer milliseconds because that is GTK's native unit.
`Jovian\Bindings\AppKit\Runtime\Bridge::pump()` takes float seconds, so this
session divides. A zero budget drains without waiting.

# Teardown, deliberately absent

AppKit has no counterpart to `finishLaunching()`, and `jovian/appkit`'s
`Lifetime` deliberately skips `release` once interpreter shutdown has begun
— releasing an `NSWindow` after `NSApp` teardown crashes, so leaking at
process exit is the intended behaviour.[^appkit-runtime] This session does
not fight that: `disconnectEngine()` withdraws presence and nothing more.

# Failure

`AppKitBridgeException` (a `Surface\Contracts\Bridge\BridgeException`) is
raised when `sharedApplication()` answers with something that is not an
`NSApplication`. Surface never names this type; a sketch catches the base
`BridgeException`.

[^session]: BridgedMacOSSession
[^provider]: VenusianAppKitServiceProvider
[^appkit-runtime]: jovian/appkit runtime — ObjCObject, identity map, Bridge
