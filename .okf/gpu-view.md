---
type: Component
title: AppKitGPUView
description: >-
  The AppKit twin of Surface's GPUView: a plain NSView whose layer is an
  adopted CAMetalLayer. This package never imports a Metal symbol.
tags: [appkit, gpu, metal, adopt, views]
status: draft
generated: { by: cursor-grok-4.6/cursor, at: "2026-09-14T00:50:00Z" }
sources:
  - id: twin
    resource: src/Views/AppKitGPUView.php
    title: AppKitGPUView
  - id: mint
    resource: src/Windows/AppKitWindowDelegate.php
    title: AppKitWindowDelegate::mintGPU
  - id: attach
    resource: src/Views/AttachesAppKitEngines.php
    title: AttachesAppKitEngines::attachEngine
  - id: spec
    resource: https://github.com/VenusianPHP/surface/blob/main/docs/superpowers/specs/2026-09-13-gpu-drawing-design.md
    title: GPU drawing slice 1 spec
---

# Overview

`mintGPU()` calls `AttachesAppKitEngines::attachEngine()`,[^attach]
which mints a host `NSView`, builds a `GPUHost` from `Bridge::pointerOf`
of that view and `NSWindow::backingScaleFactor()`, and calls
`$driver->attach($host)`; `mintGPU()` then parents the view and builds
the twin.[^mint] Stages use the same trait ([stage.md](/stage.md)). The GPU engine (today:
venusian-metal, and venusian-vulkan through MoltenVK — any LAYER engine)
returns a `GPUAttachment` with an executor and, when it renders through
a layer, raw pointer bits plus a class name (`'CAMetalLayer'`). This
package adopts those bits into AppKit's own registry and sets the layer
on the view. A `layer_pointer <= 0` is an engine error:
`executor->release()` on the attachment, then
`AppKitWindowException::engineReturnedNoLayer`. Only a refused kind
(`VULKAN_SURFACE`, `HOST_WINDOW`) answers null, and `mintGPU()` throws
`GPUViewException::unsupported($engine, 'appkit')`. AppKit never learns
a third engine exists.

Adoption happens **on this side only**. venusian-appkit never imports a
`Jovian\Bindings\Metal` or `Jovian\Venusian\Metal` symbol. The only
crossing is `adopt(pointer bits)`. Each side holds one box and owns one
retain.[^twin]

# Hold the boxes

The adopted layer box and the host `NSView` box live on `AppKitGPUView`
for the view's life. Never chain `->handle` off a temp — PHP frees the
temp the moment `->handle` is read, the destructor releases the registry
entry, and the ext resolves nil (the menu-bar Heisenbug).

`ObjCObject::box` after `adopt` may answer a `CALayer` mapped by AppKit's
ClassMap; the twin stores it as `ObjCObject`.

# Frame and pixels

`applyFrame` inverts y through `layoutSpace()` the same way
`TranslatesAppKitViewFrames` does, then refreshes
`setScale($native_window->backingScaleFactor())` and calls
`executor->resize(round(w × scale), round(h × scale))`. The executor is
resized in **pixels**, not points. `measure()` is inherited from
`GPUView` and answers the placed frame — a GPU region has no natural
size, so this twin does not share the view-frame trait (that trait's
`measure()` would override).

`queueNativeFrame()` is a no-op; the Surface tick drives frames.

# Teardown

`GPUView::remove()` (Surface) calls `executor->release()` first, then
`destroyNative()`. The twin only `removeFromSuperview`s; the held boxes
fall with the PHP object. Do not release after `NSApp` teardown —
`jovian/appkit`'s `Lifetime` already skips that, and fighting it
crashes.[^spec]

# The GL route (slice 2)

`attachEngine()` branches on `$driver->surfaceKind()`. `GL_CONTEXT` mints an
`NSOpenGLView` on a 4.1-core double-buffered `NSOpenGLPixelFormat`
(`setWantsBestResolutionOpenGLSurface(true)` so the drawable is points ×
scale), wraps it in `AppKitGLSurface`, passes that as `GPUHost->gl`,
attaches; `mintGPU()` parents the view after `attach()` and builds
`AppKitGLView` with the surface and the executor.
`makeCurrent()` re-checks that the context has a view until it does — a
context with no view has no drawable. `applyFrame` calls
`surface->update()` after `setFrame` so the context re-reads its drawable.
AppKit has no `drawRect:` callback for this; the tick drives frames.

[^twin]: AppKitGPUView
[^mint]: AppKitWindowDelegate::mintGPU
[^attach]: AttachesAppKitEngines::attachEngine
[^spec]: GPU drawing slice 1 spec
