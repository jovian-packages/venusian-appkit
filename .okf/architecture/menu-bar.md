---
type: Module
title: Menu bar
description: One NSMenu per window and one for none key - how nodes, enabled state, standard items, bare keys and what macOS adds are handled.
resource: src/Menus/
tags: [appkit, menus, macos]
status: draft
generated: { by: claude-opus/5.5, at: 2026-11-22T20:28:54Z }
sources:
  - id: bar
    resource: src/Menus/AppkitMenuBar.php
    title: AppkitMenuBar
  - id: keys
    resource: src/Menus/AppkitMenuKeys.php
    title: AppkitMenuKeys
  - id: tests
    resource: tests/Windowless/MenuBarTest.php
    title: Windowless menu tests
---

# Overview

`AppkitMenuBar` is topside's `TKMenuBar` on AppKit: it is told single operations and keeps no rule of the menus. The driver names it; `show()` is `setMainMenu`, called from `windowDidBecomeKey:`, and from `windowDidResignKey:` for the default bar.[^bar]

# Natives

| What | How |
|---|---|
| A node's place | after the last native of the sibling before it. Indexes cannot be counted: macOS appends items of its own |
| A choice | one item per option, with its state |
| Disabled in a menu | an item has no action; a submenu has an action its target does not answer. Submenus validate, so `setEnabled` is ignored there |
| Disabled in the bar | `setEnabled`: the bar does not validate |
| Edit, window and hide kinds | AppKit's selectors with no target, up the responder chain |
| Quit, replaced kinds, the app's items | an `ObjCTarget` calling `activated(uuid)`; it holds the bar weakly |
| About panel | `AppkitSession::showAbout()`. A field not given is an empty string, else AppKit shows the PHP binary's; credits are an `NSAttributedString` |

# A key with no Primary, Ctrl or Alt

AppKit asks the main menu before the focused view. `AppkitMenuKeys` withholds such an event from the pump and dispatches it with those items' key equivalents off, so the widget gets it. When no responder uses it, the window's `keyDown:` gives it to the menu.

A text view passes nothing on. With one focused, the menu is asked first only for keys text does not use: F1 to F12, except F5 alone (`complete:` in the system's `StandardKeyBinding.dict`).[^keys]

# What macOS does by itself (measured 2026-11-22)

* A menu titled Edit gets AutoFill, Start Dictation and Emoji & Symbols; a full screen item's key is shown as Fn-F, with Ctrl-Cmd-F on a hidden alternate.
* Each `setMainMenu` adds more hidden copies of those. `show()` removes exact repeats.
* It matches a key equivalent on the event's key, so a rebuilt `NSEvent` (one given a window number) does not match as a real one does.
* Cmd-Shift-Q and Cmd-Shift-/ do not reach an item.

# Checked

`tests/Windowless` on NTS and ZTS, with nothing on screen.[^tests] Not checked there, because a hidden window cannot be key: AppKit routing a real key press, the edit items through the responder chain, the About panel shown, and the swap from a real focus change.

[^bar]: AppkitMenuBar
[^keys]: AppkitMenuKeys
[^tests]: Windowless menu tests
