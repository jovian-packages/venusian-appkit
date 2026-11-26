---
type: Module
title: Session
description: NSApplication launched once, Dock presence while connected, an event pump with a budget, and a CFFileDescriptor wake.
resource: src/Appkit.php
tags: [appkit, session, macos]
status: draft
generated: { by: claude-opus/5.5, at: 2026-11-26T05:30:00Z }
sources:
  - id: appkit
    resource: src/Appkit.php
    title: Appkit (the Toolkit)
  - id: session
    resource: src/AppkitSession.php
    title: AppkitSession
  - id: driver
    resource: src/AppkitAdapterDriver.php
    title: AppkitAdapterDriver
---

# Overview

Three classes, on Topside's toolkit layer:

| Class | Extends / implements | Job |
|---|---|---|
| `AppkitAdapterDriver` | `Surface\Toolkits\Drivers\ToolkitAdapterDriver` | refuses off macOS and without ext-appkit; declares `AppkitWindow` as its window class |
| `AppkitSession` | `Surface\Toolkits\ToolkitSession` | builds the `Appkit` toolkit once: delegate, then `finishLaunching()`; then `applyColorScheme()` sets `NSApplication.appearance` to `NSAppearance appearanceNamed:` `NSAppearanceNameDarkAqua` (dark) or `NSAppearanceNameAqua` (light), nil for system (needs ext-appkit 0d3836f) |
| `Appkit` | `Surface\Contracts\Toolkits\Toolkit` | the AppKit calls below |

The window list and the after-pump settle are Topside's (`ToolkitSession`).[^session]

# Calls

| When | AppKit calls |
|---|---|
| built (once per process) | `NSApplication::sharedApplication()`; when it has no delegate, an `ObjCDelegate('NSApplicationDelegate')` that answers no selector (SDL's video subsystem makes itself the delegate of an application that has none); `finishLaunching()` |
| `connect()` | activation policy `REGULAR` (Dock icon), `activateIgnoringOtherApps(true)` |
| `disconnect()` | activation policy `PROHIBITED` |
| `pump($ns)` | `nextEventMatchingMaskUntilDateInModeDequeue(ANY, until, NSDefaultRunLoopMode, true)` in a loop: the first wait runs until now + budget, the rest until `distantPast` (no wait); each event goes to `sendEvent()` unless a withholder filter keeps it, then the after-dispatch callbacks run; then `updateWindows()` |

`withhold($owner, $filter)` and `stopWithholding($owner)` fill `withholders`; the [menu bar](/architecture/menu-bar.md) uses them for bare keys. Nothing adds to `after_dispatch` yet.[^appkit]

# Wake

`wakeDescriptor($fd)`: a `CFFileDescriptor` on the loop's descriptor, added to the main run loop in the common modes. Its callout posts an `APPLICATION_DEFINED` event at the front of the queue, which ends the pump's wait. The callout is one-shot: each `pump()` re-enables `kCFFileDescriptorReadCallBack`.[^appkit]

[^appkit]: Appkit (the Toolkit)
[^session]: AppkitSession
