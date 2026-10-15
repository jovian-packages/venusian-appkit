---
type: Module
title: Windows and menus
description: NSWindow per name, delegate mail, one app-wide NSMenu bar, default bar, About panel.
resource: src/Windows/
tags: [appkit, windows, menus, macos]
status: draft
generated: { by: claude-opus/5.5, at: 2026-10-02T22:38:01Z }
sources:
  - id: window
    resource: src/Windows/AppkitWindow.php
    title: AppkitWindow
  - id: bar
    resource: src/Windows/AppkitMenuBar.php
    title: AppkitMenuBar
  - id: driver
    resource: src/Bridge/AppkitBridgeDriver.php
    title: AppkitBridgeDriver
---

# Window

`AppkitWindow implements ToolkitWindow`. `NSWindow` titled + closable + miniaturizable + resizable, buffered, centred, title = name; `setReleasedWhenClosed(false)` (PHP holds it).[^window]

`ObjCDelegate('NSWindowDelegate')`, kept on the window object (AppKit holds delegates weakly):

| Selector | Effect |
|---|---|
| `windowDidBecomeKey:` | install own bar, or the default bar when it has none; post `WindowFocused` |
| `windowDidResignKey:` | driver shows the default bar unless another of ours is key |
| `windowDidResize:` | `postLatest("window.resized.<name>", WindowResized)`: one per pump, last size |
| `windowWillClose:` | the one close path, window still alive: remove the content tree, `forgetLatest` the window's resize, post `WindowClosed`, driver forgets the name |

* `close()` → `NSWindow::close()`; `windowWillClose:` runs inside it (also for a window never shown).
* `present()` → `activateIgnoringOtherApps(true)` + `makeKeyAndOrderFront`.
* `native()` → `NSWindow`; `menuBar()` → `AppkitMenuBar`; `isKey()`.
* Hosts primitives (`HostsPrimitives`): one content container pinned to the content view's four edges; `size()` = content view; `view()`/`uuid()`/`content()` throw once closed. See [primitives](/architecture/primitives.md).

# Menu bar

`AppkitMenuBar`: profile → `NSMenu` (`MainMenu`), one `NSMenuItem` + submenu per folder; a folder autoenables only if it holds an edit role, else autoenable off. `install()` = `NSApplication::setMainMenu`.[^bar]

* Leaf: `NSMenuItem(label, action:, hotkey lowercased)` → key equivalent with AppKit's default Command modifier.
* Edit role (`EDIT`): `NSMenuItem(label, undo:|redo:|cut:|copy:|paste:|selectAll:, z|Z|x|c|v|a)`, no target → first responder; greys out when nothing focused takes it. Posts nothing. macOS routes Cmd-C etc. to text fields only through these.
* One `ObjCTarget` per other leaf, kept in the bar (targets are weak): About → session About panel; Quit → `QuitRequested(window)`; toggle → flip state, `MenuToggled`; else `MenuActivated`.
* `setToggle`/`isToggled` → item state, no mail. `item(id)` for tests.

# Default bar

`setDefaultMenuBar(profile)`: same profile instance → no-op; else rebuilt (window `null`: items post `''`, Quit posts `null`). `showDefaultMenuBar()` installs it unless one of our windows is key; `installDefaultMenuBar()` installs unconditionally (bar-less key window). `null` profile → empty `MainMenu`.[^driver]

[^window]: AppkitWindow
[^bar]: AppkitMenuBar
[^driver]: AppkitBridgeDriver

# Right clicks

`AppkitRightClicks` per window taps the session: `AppkitSession::pump()` shows each dequeued `NSEvent` to its taps before `sendEvent:`. `RIGHT_MOUSE_DOWN` (two-finger and Magic Mouse right clicks arrive as it), or `LEFT_MOUSE_DOWN` with `NSEventModifierFlags::CONTROL`, for this window's `windowNumber` → `contentView->hitTest:` (point in the frame view), walk `superview` to the first view a registry primitive owns (`native()` or `placedView()`) → `View\RightClicked`; the content view → `WindowRightClicked`; nil (title bar) → nothing; disabled primitive or group → nothing. Points top-left: `convertPoint:fromView:nil`, y turned round unless `isFlipped`. The tap holds the window weakly and no NSWindow, and untaps on close or when it finds its window gone: a tap that kept a closed NSWindow alive put events in the queue that ended a later pump early. A push button's bounds run 7 points past its bezel on every side, outside what its superview shows.

Context menus (slice 27): after `View\RightClicked`, the menu of the primitive or its nearest enabled group with one opens. `AppkitContextMenu` fills an `NSMenu` as the bar fills a folder (separators, submenus; `ObjCTarget`s kept alive by it) and tracks it with `popUpMenuPositioningItem:atLocation:inView:` at the press in the primitive's view. Modal: the sketch's loop waits while it is open, as with the menu bar; a chosen item's target posts `MenuActivated` during tracking. The tap then `withhold()`s the press, and `AppkitSession::pump()` does not dispatch a withheld event, so the view's own menu (a text field's) stays shut. `openMenu()` is the last one built.

Canvas clicks (slice 28): `AppkitCanvasClicks` per window, a second tap on the session. `LEFT_MOUSE_DOWN` without Control (Ctrl-click is a right click) → `hitTest:` walked up superviews to a canvas's `native()` or `placedView()` (a lent surface's view sits inside), enabled with its groups → the press point from its top-left (`AppkitRightClicks::topLeft`); `LEFT_MOUSE_DRAGGED` more than 3 points (NSEvent's default drag threshold) cancels; `LEFT_MOUSE_UP` over the same canvas → `View\CanvasClicked` (`view.clicked`) at the press point. No hold rule: macOS has no long-press right click. Untaps when its window closes.

