---
type: Module
title: Session
description: NSApplication session - launch once, Dock presence on connect, event pump with budget, CFFileDescriptor wake.
resource: src/Bridge/AppkitSession.php
tags: [appkit, bridge, macos]
status: draft
generated: { by: claude-opus/5.5, at: 2026-10-01T20:06:29Z }
sources:
  - id: session
    resource: src/Bridge/AppkitSession.php
    title: AppkitSession
  - id: driver
    resource: src/Bridge/AppkitBridgeDriver.php
    title: AppkitBridgeDriver
---

# Overview

`AppkitSession extends Surface\Bridge\BridgedToolkitSession`; built and cached by `AppkitBridgeDriver::connect()`. macOS only (needs ext-appkit).[^driver]

| Hook | AppKit calls |
|---|---|
| initialize (once) | `NSApplication::sharedApplication()`, `finishLaunching()` |
| connect | activation policy `REGULAR` (Dock icon, app switcher), `activateIgnoringOtherApps(true)` |
| disconnect | activation policy `PROHIBITED`: headless, windows untouched |
| `pump($ns)` | `nextEventMatchingMask(ANY, until, default mode, dequeue)` + `sendEvent`; first wait ends at `now + budget`, rest at `distantPast` (drain); then `updateWindows()` |

`activateIgnoringOtherApps`, not `activate`: macOS declines the cooperative request for a terminal-launched process, and no window of an inactive app becomes key.[^session]

# Wake

`wakeDescriptor($fd)`: `CFFileDescriptor` on the loop's fd, run-loop source in common modes on the main run loop. Callout posts an `APPLICATION_DEFINED` NSEvent at the queue head → ends `nextEventMatchingMask`'s wait. Callbacks re-enabled at the top of every pump (one-shot). Release: remove source, invalidate.

# About

`showAbout(array)`: `orderFrontStandardAboutPanelWithOptions`, keys `ApplicationName`, `ApplicationVersion` + `Version`, `Copyright` from `windows.about`; empty values omitted. Standard panel, non-modal, AppKit keeps one.

[^session]: AppkitSession
[^driver]: AppkitBridgeDriver
