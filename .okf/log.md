# Log

## 2026-10-23

* Review fixes (C9): a disabled focusable canvas (itself or through a container) answers acceptsFirstResponder NO and lets go of focus, as GTK and Qt give a disabled widget none; present()'s hold on the first responder ends at windowDidBecomeKey: (AppKit picks it inside makeKeyAndOrderFront:), so the click that makes the window key posts its focus move; the tracker skips the look through the primitives while firstResponder is unchanged; `setInitialFocus()` refuses another window's primitive.

## 2026-10-22

* Fix: a text input posted TextSubmitted whenever its editing ended (a Tab out, a click elsewhere, present()): AppKit sends the field's action then too. Only Return and keypad Enter post it now, as on GTK and Qt. present() no longer makes an initial focus first responder again when AppKit already did.
* Focus and window keys (compare-app toolkit C9): `focus()` / `hasFocus()` on every primitive through `focusView()`, `focused()` / `setInitialFocus()` on the window, focus mail compared after each dispatched event (`AppkitFocus`, `AppkitSession::afterDispatch`), focusable canvases (`ObjCFocusView`), `WindowKeyPressed` from the `ObjCKeyWindow`'s `keyDown:` (`AppkitWindowKeys`). Found: a field editor takes every key, F5 included; hide and disable resign first responder, so they run quietly. [windows and menus](architecture/windows-and-menus.md)
* Dialogs, clipboard and desktop (compare-app toolkit C7): `AppkitDialogs` (alerts and panels as sheets; key equivalents from the roles; `AppkitAlertEscape` answers Esc when the default is also the cancel; the parent's close ends the sheet with `NSModalResponseCancel`), `AppkitClipboard` (`NSPasteboard`), `AppkitDesktop` (`NSWorkspace::openURL`). A window with an open dialog keeps its close button's close. [windows and menus](architecture/windows-and-menus.md)

## 2026-10-20

* Modal children, close veto and button roles (compare-app toolkit C6): a child window is a sheet; `confirmClose()` through `windowShouldClose:`; `AppkitButtonKeys` (Return / Enter / Esc to the role holders); the default bar blocked while a child is key. [windows and menus](architecture/windows-and-menus.md)
* Menu enabled state and accelerators (compare-app toolkit C5): `setItemEnabled()` on bars, windows and the driver's default profile; `keyEquivalent()` / `modifierMask()` from `Accelerator`; folders in autoenabling Edit menus disabled through a target that lacks the action. [windows and menus](architecture/windows-and-menus.md)

## 2026-10-19

* Canvas pointer and wheel (compare-app toolkit C4): `AppkitCanvasPointer` per window, a session tap after the right clicks, posting `CanvasPressed`, `CanvasDragged`, `CanvasReleased` and `CanvasWheeled` for a canvas that watches the pointer, one press owned at a time; a press a context menu withheld ends at once, a wheel over a watching canvas is withheld from dispatch. `AppkitCanvasClicks::canvasAt()` and `AppkitInputEngine::modifiers()` are public static. [windows and menus](architecture/windows-and-menus.md)
* Removed primitives are freed. The ext's glue objects (ObjCTarget, ObjCDelegate, ObjCObserver) hold their callables where PHP's collector cannot see them, so a callable capturing `$this` kept the button, checkbox, toggle, toggle button, slider, dropdown, date picker, text input, text area, table, video and tab view alive forever after `remove()`. Each now reaches its primitive through a `WeakReference` from static closures. `FreedTest` builds, removes and collects every kind.

## 2026-10-18

* AppkitTree: an NSOutlineView over one cached NSString item per node id, with label cells in the node's colours. NodeSelected, NodeExpanded and NodeActivated post only for user actions; NodeActivated comes from the double action and from Return / Enter through a key-down monitor. AppKit's own closing and reopening of expanded descendants posts nothing, and a descendant code collapsed while hidden is kept collapsed. Requires ext-appkit ^0.10.3 and Surface contracts and windows ^0.10.3.

## 2026-10-16

* Composes what ext-appkit 0.10.2 (1:1) no longer decides: layer setters are `setWantsLayer(true)` + `layer()->set…()`, the Metal lend sets `wantsLayer` itself and the reclaim toggles it; `ObjCStageWindow` answers `canBecomeKeyWindow` / `canBecomeMainWindow` from `focusable`; the GL view's draw handler makes its context current and flushes it; CG and I/O Kit return codes and null CFData / CGImage / GL view become `WindowException`; grids pass `NSGridCell::emptyContentView()`.
* AppkitSplitView (NSSplitView, equal first layout, positions as fractions, SplitMoved from a monitor that dispatches a divider mouse-down itself) and AppkitTabView (NSTabView, TabSelected for user switches); ext-appkit ^0.10.2 (compare-app split and tabs, C2).

## 2026-10-14

* AppkitTextArea: read-only, wrap, monospace, text-block line backgrounds, range colours, line selection over UTF-16, clip-view scroll position and ViewScrolled (compare-app text panes, C1).

## 2026-10-10

* AppkitLink: TKLink over a selectable label with an NSLinkAttributeName attributed text (toolkit links).
* Edit menu: edit roles become first-responder NSMenuItems with the standard key equivalents; their folder autoenables (HumanInput slice 29).

## 2026-10-09

* HumanInput on AppKit: `AppkitInputEngine` (`SeenEvent`, `SeenKind`; keys through Surface's `MacKeycodes`), `GameControllerPadSource` (`GcReading`, `GameControllers`, `SystemGameControllers`); the provider registers both. Requires `venusian-surface/human-input`. [input](architecture/input.md)
* Canvas clicks: `AppkitCanvasClicks` posts `view.clicked` with the point for a press and release on a canvas (HumanInput slice 28). [windows and menus](architecture/windows-and-menus.md)
* Context menus: `AppkitContextMenu`, an `NSMenu` popped up by `AppkitRightClicks` for the primitive or nearest group with a menu; items post `MenuActivated`. `AppkitSession::withhold()` / `withheld()`: a tap keeps the event it answered from being dispatched (HumanInput slice 27). Requires ext-appkit `NSMenu` popUp. [windows and menus](architecture/windows-and-menus.md)
* Right-click mail: `AppkitRightClicks` posts `view.right-clicked` / `window.right-clicked` for the right button and Ctrl-click; the session's pump shows each event to its taps before dispatching it (HumanInput slice 26). [windows and menus](architecture/windows-and-menus.md)

## 2026-10-08

* The driver stages windows: `AppkitStagedWindow` and `AppkitDisplays`. Lending moved from `AppkitCanvas` into `LendsAppkitSurfaces`, shared with staged windows. `applyPixels()` takes the damage list. [staged windows](architecture/staged-windows.md)

## 2026-10-06

* The `vulkan` engine presents through the canvas's `CAMetalLayer` (MoltenVK); tests only, no canvas change. [primitives](architecture/primitives.md)
* `AppkitCanvas` lends an `NSOpenGLView`'s context to the `opengl` engine: the view pinned over the canvas, its `drawRect:` copying the frame. [primitives](architecture/primitives.md)

## 2026-10-05

* `AppkitCanvas` lends an SDL window over its `NSWindow` to the `sdl3` engine: SDL's swapchain view moved into the canvas, the window destroyed once the device lets go. [primitives](architecture/primitives.md)
* The session gives the application an empty `NSApplicationDelegate` trampoline when it has none (SDL takes an empty delegate slot) and sweeps parked SDL windows every pump; the canvas restores the responder chain SDL rewires. [session](architecture/session.md), [primitives](architecture/primitives.md)
* `AppkitCanvas` lends a `CAMetalLayer` (ext-metal) as the view's layer to the `metal` engine; reclaim restores a backing layer with gravity and clipping. [primitives](architecture/primitives.md)

## 2026-10-04

* `AppkitCanvas` pipes an ext-fb framebuffer: `CFData::create` reads its memory by address, so no pixel byte passes through PHP. [primitives](architecture/primitives.md)

## 2026-10-03

* `AppkitCanvas`: the framebuffer a `TKCanvas` hands out, shown as layer contents. [primitives](architecture/primitives.md)

## 2026-10-02

* Stacks switch to FILL distribution while a child fills: [primitives](architecture/primitives.md) — a filling column beside a hugging one now takes every spare point of its row.
* Review fixes: [primitives](architecture/primitives.md) — grid in a frame view (no window shrink), fillers share equally, slot for main-axis align, priorities table (480 pull, 490 wrap), hidden fillers, natural sizes for table/text area, fixed min fitting, video loop/replay/setFile/disabled, own-background leaves.
* Toolkit primitives: [primitives](architecture/primitives.md) added; [windows and menus](architecture/windows-and-menus.md) gains content hosting, `WindowResized`, close order; [testing](runbooks/testing.md) gains `pumpUntil`, primitive coverage, condition waits.

## 2026-10-01

* Bundle created: [session](architecture/session.md), [windows and menus](architecture/windows-and-menus.md), [testing](runbooks/testing.md).
