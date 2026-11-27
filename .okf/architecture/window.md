---
type: Module
title: Window
description: An ObjCKeyWindow with an NSWindowDelegate wired to Topside's window lifecycle; resizes and backing-scale changes from the delegate.
resource: src/AppkitWindow.php
tags: [appkit, windows, macos]
status: draft
generated: { by: claude-opus/5.5, at: 2026-11-22T20:28:54Z }
verified:
  - { by: claude-opus/5.5, at: 2026-11-20T21:20:00Z }
sources:
  - id: window
    resource: src/AppkitWindow.php
    title: AppkitWindow
---

# Overview

`AppkitWindow extends Surface\Toolkits\ToolkitWindow`. The close path, confirm-close, `close()` from code, resize mail and the after-pump `settle()` call are Topside's; this class builds the native window and connects its delegate to them.[^window]

# Native

* `ObjCKeyWindow::initWithContentRectStyleMaskBackingDefer`: titled, closable, miniaturizable, resizable; buffered.
* `setReleasedWhenClosed(false)`, title = name, `center()`, `setAutorecalculatesKeyViewLoop(true)` (Tab walks views in on-screen order).
* `native()` = the `NSWindow`; `size()` = the content view's frame, in points.

# Delegate

One `ObjCDelegate('NSWindowDelegate')`, kept on the window (AppKit holds delegates weakly).

| Selector | Goes to |
|---|---|
| `windowDidBecomeKey:` | `gainedFocus()` (its [bar](/architecture/menu-bar.md) is shown then and there), then `WindowFocused` |
| `windowDidResignKey:` | `lostFocus()`: the default bar |
| `windowShouldClose:` | `allowsUserClose()`: false keeps the window while confirming |
| `windowWillClose:` | the close path |
| `windowDidResize:` | `resized()` with the content view's size: one `WindowResized` per pump |
| `windowDidChangeBackingProperties:` | `WindowScaleChanged` when the backing scale differs from the last posted |

# Present and close

* `present()`: a child begins a sheet on its parent (presenting a hidden parent first); a top-level window activates the app and `makeKeyAndOrderFront`.
* `closeNative()` = `NSWindow::close()`: it sends `windowWillClose:` and skips `windowShouldClose:`, so a confirming window closes too.
* `releaseNative()` drops the content constraints and sets the delegate to null before letting the window go: the `NSWindow` can outlive this object.

# Save panel

`showSave()` (topside 9ca0235): an `NSSavePanel` begun as a sheet on the window (`beginSheetModalForWindowCompletionHandler`). `SaveRequest::$name` is the panel's `nameFieldStringValue`, `$directory` its `directoryURL` (`NSURL::fileURLWithPath`), `$title` its `message` (a sheet has no title bar). AppKit asks itself before replacing a file. The completion handler answers `saveAnswered()` with the panel's `URL()->path()` for `NSModalResponseOK`, null otherwise. `closeSave()` ends the sheet (`endSheetReturnCode`, `NSModalResponseCancel`) before the window closes; its handler then finds the panel gone and posts nothing. `tests/Integration/SaveTest.php`: 4 cases; the panel's buttons cannot be pressed without a click, so the tests end the sheet with `endSheetReturnCode` as OK and as Cancel.

# Alert

`showAlert()`: an `NSAlert` begun as a sheet on the window (`beginSheetModalForWindowCompletionHandler`), the buttons added in order (the first rightmost). Every button's key equivalent is set: Return on the default, Esc on the cancel; one button holds one, so when the default is also the cancel Esc presses none, as in every AppKit alert (the bridge-era key-down monitor that answered Esc there is not ported). Style as `NSAlertStyle`, suppression as the alert's suppression button, no title (a sheet has none). `closeAlert()` ends the sheet before the window closes. `tests/Integration/AlertTest.php`: 6 cases, `performClick` on the alert's own buttons.

# Browse

`openUrl()`: `NSWorkspace::sharedWorkspace()->openURL()` with the address as an `NSURL`.

# Not ported yet

From the [bridge-era window](/architecture/windows-and-menus.md): focus tracking, window and button-role keys, right clicks, clipboard compare on key.

[^window]: AppkitWindow
