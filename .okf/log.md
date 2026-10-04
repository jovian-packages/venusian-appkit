# Log

## 2026-10-03

* `AppkitCanvas`: the framebuffer a `TKCanvas` hands out, shown as layer contents. [primitives](architecture/primitives.md)

## 2026-10-02

* Stacks switch to FILL distribution while a child fills: [primitives](architecture/primitives.md) — a filling column beside a hugging one now takes every spare point of its row.
* Review fixes: [primitives](architecture/primitives.md) — grid in a frame view (no window shrink), fillers share equally, slot for main-axis align, priorities table (480 pull, 490 wrap), hidden fillers, natural sizes for table/text area, fixed min fitting, video loop/replay/setFile/disabled, own-background leaves.
* Toolkit primitives: [primitives](architecture/primitives.md) added; [windows and menus](architecture/windows-and-menus.md) gains content hosting, `WindowResized`, close order; [testing](runbooks/testing.md) gains `pumpUntil`, primitive coverage, condition waits.

## 2026-10-01

* Bundle created: [session](architecture/session.md), [windows and menus](architecture/windows-and-menus.md), [testing](runbooks/testing.md).
