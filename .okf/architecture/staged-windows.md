---
type: Module
title: Staged windows
description: AppkitStagedWindow on ObjCStageWindow and ObjCDrawView; partial present, modes incl. Spaces and exclusive fullscreen, AppkitDisplays, capabilities, lending through LendsAppkitSurfaces.
resource: src/Windows/AppkitStagedWindow.php
tags: [appkit, staged-windows, fullscreen, displays]
status: draft
generated: { by: claude-opus/5.5, at: 2026-10-08T12:00:00Z }
sources:
  - id: window
    resource: src/Windows/AppkitStagedWindow.php
    title: AppkitStagedWindow
  - id: displays
    resource: src/Windows/AppkitDisplays.php
    title: AppkitDisplays
  - id: lending
    resource: src/Windows/Concerns/LendsAppkitSurfaces.php
    title: LendsAppkitSurfaces
---

# Overview

`AppkitBridgeDriver` implements `StagedWindowDriver`: `openStaged()` validates options with `StagedWindow::options()` and makes an `AppkitStagedWindow`; `forgetStaged()` on close. Backend `appkit/cocoa`.[^window]

Native window: `ObjCStageWindow` (key status a flag: borderless takes focus, `focusable: false` refuses), collection behavior full-screen primary, level floating for always-on-top, clear and non-opaque for transparent. Content view: `ObjCDrawView`, layer-backed, redraw policy on-set-needs-display, black layer background (none when transparent).

# Present

`applyPixels` / `applyAddress` copy the frame into a `CFData` and make a `CGImage` (sRGB, skip-last, or alpha-last when transparent). Damage regions map through `presentRect()` into view points (y up) and go to `setNeedsDisplayInRect:`; no damage or a new size marks the whole view. Vsync `On`: AppKit draws in its display cycle; otherwise `displayIfNeeded` at once. `drawRect:` clips to the dirty rect, sets interpolation (none for Nearest, high for Linear), draws the image into `presentRect()`.

# Modes

`syncMode()` reads the native state (captured display → Exclusive, full-screen style bit → Fullscreen, miniaturized, zoomed, else Windowed) and posts `modeChanged()` when it differs from the last reported. Called after each applied mode and from miniaturize, deminiaturize, resize and full-screen notifications.

* Not shown (a miniaturized window counts as shown), or a full-screen transition running: the mode waits in `pending`, applied on show or when the transition ends.
* In a Space and asked for anything else: toggle out first, the asked mode pending.
* Fullscreen: `toggleFullScreen:`; a display passed moves the window there first.
* Exclusive: `AppkitDisplays::nativeMode()` finds the `CGDisplayMode`; the desktop mode is held, the display captured, its mode set, the window borderless at the shielding level over `CGDisplay::bounds`. Leaving restores mode, releases, restores frame, style and level. Close tears down the same way.

# Displays

`AppkitDisplays`: id = `NSScreenNumber` (`CGDirectDisplayID`). Bounds and usable from `frame` / `visibleFrame`, flipped to desktop coordinates (top-left of the menu-bar screen, y down). Modes from `copyAllDisplayModes` with low-resolution duplicates, usable-for-GUI only; a zero refresh rate reads the screen's maximum frames per second. Desktop mode = the held pre-exclusive mode, else current. HDR from the screen's EDR values.[^displays]

# Rest

Position: content rect top-left in desktop coordinates; `setFrameOrigin:`. Limits: content min/max size. Aspect: exact ratio native; a range corrected after each resize. Style changes keep the content rect. Opacity `alphaValue`; keep-awake an IOPM display-sleep assertion; attention a critical Dock request; icon the app's Dock icon. Frame clock: `CADisplayLink` on the view (macOS 14+) → `frameDue()`. Close box: `windowShouldClose:` closes unless `confirm_close`, which posts `WindowCloseRequested`. Notifications also give focus, move, screen, backing scale and occlusion mail.

# Lending

`LendsAppkitSurfaces` (shared with `AppkitCanvas`): Metal layer as the view's layer, SDL window over the NSWindow with SDL's swapchain view pinned into the view, `NSOpenGLView` pinned over the view. The session pump sweeps parked SDL windows for both.[^lending]

[^window]: AppkitStagedWindow
[^displays]: AppkitDisplays
[^lending]: LendsAppkitSurfaces
