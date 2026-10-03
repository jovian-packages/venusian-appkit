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

`AppkitMenuBar`: profile → `NSMenu` (`MainMenu`), one `NSMenuItem` + submenu per folder, autoenable off. `install()` = `NSApplication::setMainMenu`.[^bar]

* Leaf: `NSMenuItem(label, action:, hotkey lowercased)` → key equivalent with AppKit's default Command modifier.
* One `ObjCTarget` per leaf, kept in the bar (targets are weak): About → session About panel; Quit → `QuitRequested(window)`; toggle → flip state, `MenuToggled`; else `MenuActivated`.
* `setToggle`/`isToggled` → item state, no mail. `item(id)` for tests.

# Default bar

`setDefaultMenuBar(profile)`: same profile instance → no-op; else rebuilt (window `null`: items post `''`, Quit posts `null`). `showDefaultMenuBar()` installs it unless one of our windows is key; `installDefaultMenuBar()` installs unconditionally (bar-less key window). `null` profile → empty `MainMenu`.[^driver]

[^window]: AppkitWindow
[^bar]: AppkitMenuBar
[^driver]: AppkitBridgeDriver
