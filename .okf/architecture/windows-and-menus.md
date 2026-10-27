---
type: Module
title: Windows and menus
description: NSWindow per name, delegate mail, one app-wide NSMenu bar, default bar, About panel.
resource: src/Windows/
tags: [appkit, windows, menus, macos]
status: draft
generated: { by: claude-opus/5.5, at: 2026-10-22T13:00:00Z }
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
| `windowDidBecomeKey:` | install own bar, or the default bar when it has none (a child: the default bar, blocked); post `WindowFocused` |
| `windowShouldClose:` | the close button: while `confirmClose()`, post `WindowCloseRequested` and answer NO |
| `windowDidResignKey:` | driver shows the default bar unless another of ours is key |
| `windowDidResize:` | `postLatest("window.resized.<name>", WindowResized)`: one per pump, last size |
| `windowWillClose:` | the one close path, window still alive: remove the content tree, `forgetLatest` the window's resize, post `WindowClosed`, driver forgets the name |

* `close()` → the child's `close()` first; a child calls `endSheet` on the parent; then `NSWindow::close()` (it never asks `windowShouldClose:`, so a confirming window closes); `windowWillClose:` runs inside it (also for a window never shown). AppKit hides a parent's sheet on close but does not end it, so the order matters.
* `present()` → `activateIgnoringOtherApps(true)` + `makeKeyAndOrderFront`. A child is a sheet: its parent is presented first when hidden, then `beginSheetCompletionHandler(child, null)` one time. AppKit disables the parent's close button while the sheet is attached; a sheet has no close button.
* Button roles (`AppkitButtonKeys`, a session tap like the right clicks): Return (36) / keypad Enter (76) → the DEFAULT holder, Esc (53) → the CANCEL holder, with no Cmd/Ctrl/Opt/Shift; the key is withheld and the button gets `performClick`. Return goes on to a focused text area (`AppkitTextArea::textView()` is the first responder). A holder that is disabled, hidden or out of the window (a tab not selected) lets the key go on. Esc in a child with no cancel holder is `userClose()`. The DEFAULT holder has key equivalent `"\r"` for the default-button look; a focused `NSTextView` keeps Return, and a disabled or hidden button never fires it (probe 2026-10-20). A focused `NSTextField` with an action would send its action and then click the default button: the tap prevents that.
* `native()` → `NSWindow`; `menuBar()` → `AppkitMenuBar`; `isKey()`.
* Hosts primitives (`HostsPrimitives`): one content container pinned to the content view's four edges; `size()` = content view; `view()`/`uuid()`/`content()` throw once closed. See [primitives](/architecture/primitives.md).

# Menu bar

`AppkitMenuBar`: profile → `NSMenu` (`MainMenu`), one `NSMenuItem` + submenu per folder; a folder autoenables only if it holds an edit role, else autoenable off. `install()` = `NSApplication::setMainMenu`.[^bar]

* Leaf: `NSMenuItem(label, action:, keyEquivalent($keys))` + `setKeyEquivalentModifierMask(modifierMask($keys))`: letters lower case, Shift only in the mask; function keys as their `NS*FunctionKey` characters; Backspace = `NSBackspaceCharacter` (the Delete key's U+007F chooses it); Escape `"\e"`. AppKit matches Shift exactly only for key events that carry their Quartz event (tests: `CGEvent::createKeyboardEvent` → `NSEvent::eventWithCGEvent`).
* Enabled state: `MenuStates`; `apply(id)` for the node and every node below it. Autoenable off → `setEnabled(effective)`. Autoenable on (a folder with edit roles; AppKit ignores `setEnabled`): item → action `ObjCTarget::ACTION` or null; folder → target itself + `DISABLED_FOLDER` (`venusianDisabledFolder:`, not implemented) or a no-op `ObjCTarget` + `ObjCTarget::ACTION` (a validated-disabled folder with no action stays disabled). Edit roles keep their actions. A disabled folder's children never match a key equivalent.
* Edit role (`EDIT`): `NSMenuItem(label, undo:|redo:|cut:|copy:|paste:|selectAll:, z|Z|x|c|v|a)`, no target → first responder; greys out when nothing focused takes it. Posts nothing. macOS routes Cmd-C etc. to text fields only through these.
* One `ObjCTarget` per other leaf, kept in the bar (targets are weak): About → session About panel; Quit → `QuitRequested(window)`; toggle → flip state, `MenuToggled`; else `MenuActivated`.
* `setToggle`/`isToggled` → item state, no mail. `item(id)` for tests.

# Default bar

`setDefaultMenuBar(profile)`: same profile instance → no-op; else rebuilt (window `null`: items post `''`, Quit posts `null`). `showDefaultMenuBar()` installs it unless one of our windows is key; `installDefaultMenuBar(blocked)` installs unconditionally (bar-less key window). `null` profile → empty `MainMenu`. Blocked (a child is key): `AppkitMenuBar::block(true)` shows every item but the edit roles as disabled, `MenuStates` unchanged; every other install unblocks it.[^driver]

[^window]: AppkitWindow
[^bar]: AppkitMenuBar
[^driver]: AppkitBridgeDriver

# Right clicks

`AppkitRightClicks` per window taps the session: `AppkitSession::pump()` shows each dequeued `NSEvent` to its taps before `sendEvent:`. `RIGHT_MOUSE_DOWN` (two-finger and Magic Mouse right clicks arrive as it), or `LEFT_MOUSE_DOWN` with `NSEventModifierFlags::CONTROL`, for this window's `windowNumber` → `contentView->hitTest:` (point in the frame view), walk `superview` to the first view a registry primitive owns (`native()` or `placedView()`) → `View\RightClicked`; the content view → `WindowRightClicked`; nil (title bar) → nothing; disabled primitive or group → nothing. Points top-left: `convertPoint:fromView:nil`, y turned round unless `isFlipped`. The tap holds the window weakly and no NSWindow, and untaps on close or when it finds its window gone: a tap that kept a closed NSWindow alive put events in the queue that ended a later pump early. A push button's bounds run 7 points past its bezel on every side, outside what its superview shows.

Context menus: after `View\RightClicked`, the menu of the primitive or its nearest enabled group with one opens. `AppkitContextMenu` fills an `NSMenu` as the bar fills a folder (separators, submenus; `ObjCTarget`s kept alive by it) and tracks it with `popUpMenuPositioningItem:atLocation:inView:` at the press in the primitive's view. Modal: the sketch's loop waits while it is open, as with the menu bar; a chosen item's target posts `MenuActivated` during tracking. The tap then `withhold()`s the press, and `AppkitSession::pump()` does not dispatch a withheld event, so the view's own menu (a text field's) stays shut. `openMenu()` is the last one built.

Canvas clicks: `AppkitCanvasClicks` per window, a second tap on the session. `LEFT_MOUSE_DOWN` without Control (Ctrl-click is a right click) → `hitTest:` walked up superviews to a canvas's `native()` or `placedView()` (a lent surface's view sits inside), enabled with its groups → the press point from its top-left (`AppkitRightClicks::topLeft`); `LEFT_MOUSE_DRAGGED` more than 3 points (NSEvent's default drag threshold) cancels; `LEFT_MOUSE_UP` over the same canvas → `View\CanvasClicked` (`view.clicked`) at the press point. No hold rule: macOS has no long-press right click. Untaps when its window closes.

The routing is shared: `AppkitCanvasClicks::canvasAt(window, point)` is public static, and the pointer below uses it.

# Canvas pointer

Canvas pointer and wheel (C4): `AppkitCanvasPointer` per window, a third tap on the session, tapped after `AppkitRightClicks`: their context menu runs inside their tap, before this one sees the press, and withholds it, so a press that arrives withheld opened a menu that has already taken its release.

* `see()` keeps this window's events (window number) and hands them to `seen(event, windowPoint)`, which is public: an `NSEvent` made from a `CGEvent` (scroll wheels, the other buttons) reads window number 0, so tests call it with a point.
* `LEFT/RIGHT/OTHER_MOUSE_DOWN` on a watching canvas (`canvasAt`, enabled with its groups) owns the press and posts `CanvasPressed` (`clickCount()`); `…_DRAGGED` posts `CanvasDragged` only when the point changed; the owning button's `…_UP` posts `CanvasReleased`. Points from `AppkitRightClicks::topLeft` on the view `canvasAt` found, so drags outside the canvas and the window come out negative or past its size. Buttons: left and right by type, the others by `buttonNumber()` (2 middle, 3 X1, 4 X2; others post nothing). Modifiers through `AppkitInputEngine::modifiers()`.
* Another button's press or release posts nothing while a press is owned. A withheld press ends the owned press at its last point (`interrupt()`); a withheld press that starts one posts `CanvasPressed` and `CanvasReleased` together.
* Every event of an owned press re-checks the canvas: removed, no longer watching, or disabled itself or inside a group drops the press silently.
* `SCROLL_WHEEL` over a watching canvas → `CanvasWheeled(-scrollingDeltaX, -scrollingDeltaY, hasPreciseScrollingDeltas)`, and the event is withheld from dispatch, so an enclosing scroll view never scrolls with it. Non-precise deltas are lines: a notch is 1.0 as it comes.
* Untaps when its window closes.

# Focus and window keys

* Focus (`AppkitFocus`): after each event the session dispatches (`AppkitSession::afterDispatch`), the window's `firstResponder` is compared with the holder last seen. Inside one event the responder can pass through the window (Return in a text field ends editing, then selects the field again), so it is not observed tick by tick. The holder is the primitive whose `focusView()` (the native, or a text area's `NSTextView`, a table's `NSTableView`, a tree's `NSOutlineView`) is the responder or its nearest superview (a text field's field editor). `focus()`, `present()`, hide, disable and a canvas that stops being focusable run inside `quietly()`: no mail, and the holder after it is what the user's next move leaves. A window not yet key when presented absorbs the first responder AppKit sets on becoming key. Holders are weak references.
* `focus()` refuses a focus view that is out of the window or answers `acceptsFirstResponder` NO: `makeFirstResponder:` takes any view.
* A canvas's native is an `ObjCFocusView`; its `acceptsFirstResponder` handler answers `isFocusable()`. `AppkitFocus` is also a tap: a left press (no Control) on a focusable canvas makes it first responder before dispatch, since a lent `NSOpenGLView` over it takes none.
* Window keys (`AppkitWindowKeys`): the window is an `ObjCKeyWindow`; its `keyDown:` handler posts `WindowKeyPressed` (key from `MacKeycodes::key(keyCode)`, text from `charactersIgnoringModifiers` without control characters and U+F700–U+F8FF, modifiers from `AppkitInputEngine::modifiers`, `isARepeat`) and returns true. Tab (key code 48) returns false, so NSWindow walks the key-view loop (`setAutorecalculatesKeyViewLoop(true)`), except true while the focused canvas takes Tab. A field editor takes every key it is sent, so no key reaches `keyDown:` from a text input.
* Canvas keys (`AppkitCanvasKeys`, one per canvas, on its `ObjCFocusView`): while the view is first responder, `keyDown:` posts `KeyPressed` (the same key, text and modifier reads as the window keys) and hands the event to `interpretKeyEvents:`; the view's `insertText:replacementRange:` posts `TextCommitted` (dead keys compose in the view's input context, which activates on the run-loop pass after it became first responder) and `doCommandBySelector:` posts `EditCommanded` from the NSStandardKeyBindingResponding selector table (`moveLeft:` … `cancelOperation:`; Home and End scroll on macOS and post nothing). The Edit menu's actions (`cut:`, `copy:`, `paste:`, `selectAll:`, `undo:`, `redo:`) reach the view as first responder and post `EditCommanded`; the view answers them while handled, so those items enable while a canvas is focused, and their key equivalents (Command-A …) post the command and no `KeyPressed`. Every taken key returns true, so no `WindowKeyPressed` posts for it. `keyUp:` posts `KeyReleased` for a key whose `KeyPressed` posted, once; a local key-up monitor posts it while Command is down, since NSApplication then sends no `keyUp:`. `flagsChanged:` reads the event key's own flag: set posts its `KeyPressed`, clear its `KeyReleased`; then each modifier reported pressed whose flag is gone is released, whatever key the event names (focus that left mid-chord comes back as one change naming key code 0). Tab and Shift-Tab are declined when the canvas does not take Tab. Key equivalents (menu accelerators, default and cancel buttons) run before `keyDown:`, so a canvas never sees them. Handlers go off in `releaseNative()`.

# Dialogs

Dialogs (C7): `AppkitDialogs extends ToolkitDialogs` (Surface holds the request ids and the one-modal-per-parent rule). Every alert and panel is a sheet on its parent's `NSWindow`.

* Alert: `NSAlert`; each button's key equivalent is `"\r"` for the default, `"\e"` for the cancel, `""` for the rest (AppKit gives Return to the first button otherwise). When the default is also the cancel, `AppkitAlertEscape`, a tap on the session, answers Esc (key code 53) at the alert's window with `endSheetReturnCode(…, NSAlertFirstButtonReturn + cancel)`. The completion block answers `code - NSAlertFirstButtonReturn`.
* Panels: `NSOpenPanel` (files, folders, multiple) and `NSSavePanel`; `title` is the panel's message; a request directory that is a folder is the `directoryURL`. `NSModalResponseOK` answers the chosen paths, any other response `[]`.
* `closeOn(parent)` (from `AppkitWindow::close()`, after the child) forgets the id, ends the sheet with `NSModalResponseCancel`, then posts `-1` / `[]`; the late completion block finds no id and posts nothing. `shouldClose()` refuses the close button while `openOn()` has an id.
* Closures given to AppKit reach `AppkitDialogs` through `WeakReference` from `static` closures.
* An `NSOpenPanel` sheet posts the private event type 21; `NSEvent::type()` reads it as the int.
