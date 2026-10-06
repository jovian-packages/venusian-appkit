# Log

## 2026-10-06

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
