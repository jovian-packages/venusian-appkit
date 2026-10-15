# Log

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
