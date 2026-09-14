---
type: Component
title: AppKitStageSession
description: >-
  The stage.appkit host: whole NSWindows owned by a GPU engine (Metal,
  OpenGL, Vulkan via MoltenVK). Rides the native bridge session and pump.
tags: [appkit, stage, gpu, metal, opengl, vulkan]
status: draft
generated: { by: claude-opus-5/claude-code, at: "2026-09-14T12:00:00Z" }
sources:
  - id: session
    resource: src/Sessions/AppKitStageSession.php
    title: AppKitStageSession
  - id: stage
    resource: src/Stages/AppKitStagedWindow.php
    title: AppKitStagedWindow
  - id: attach
    resource: src/Views/AttachesAppKitEngines.php
    title: AttachesAppKitEngines
  - id: abstract
    resource: https://github.com/VenusianPHP/surface/blob/main/src/Surface/Stage/StagedWindow.php
    title: Surface StagedWindow abstract
---

# Overview

Provider binds `AppKitStageSession` singleton under `stage.appkit`.[^session]
Surface's `StageManager` resolves that alias; nothing names the class.

# Session

* Rides `BridgedMacOSSession`: same `NSApplication`, same pump.
  `sharesNativePump()` true — stage resource skips its own pump while the
  `os` resource is docked.
* `initializeEngine()` no-op: bridge boots AppKit at construction.
  `connectToEngine()` = `bridge->connect()`. `disconnectEngine()` no-op:
  bridge outlives stages. `pumpEngine()` = `bridge->pump()`.
* `mintStage()`: `NSWindow` (`TITLED|CLOSABLE|MINIATURIZABLE|RESIZABLE`,
  buffered), `setReleasedWhenClosed(false)`, `attachEngine()` at the
  content size in points, engine's view becomes content view (width +
  height sizable), `center()`. Minted hidden.
* Every mint failure is a `StageException`; a sketch catches that alone.
  Window mint → `windowMintFailed`. Null from `attachEngine()`
  (`VULKAN_SURFACE`, `HOST_WINDOW`) → window closed, inherited
  `unsupported`. Any attach throw → window closed; a `StageException`
  passes through; an `AppKitWindowException` (view mint, adopt) becomes
  `viewMintFailed`; anything else (the engine's `attach()`) becomes
  inherited `attachFailed` (host + engine named). Previous kept. No layer
  → `engineReturnedNoLayer` via the `noLayerError()` override. A failure
  after attach (content view, delegate, stage ctor) → executor released,
  window closed, `windowSetupFailed`.

# Shared attach

`AttachesAppKitEngines::attachEngine()`[^attach] is the one attach path for
GPU regions (`AppKitWindowDelegate::mintGPU`) and stages. Decides by
`SurfaceKind`: `LAYER` mints a plain `NSView` and adopts the handed-back
layer bits; `GL_CONTEXT` mints an `NSOpenGLView` and lends
`AppKitGLSurface`. Returns `AppKitAttachment` (view + `GPUAttachment` +
exactly one of layer/gl), boxes held for the host's life. Null = refused
by kind only; caller parents the view and throws its own refusal. A LAYER
engine handing back no layer releases its executor and throws
`noLayerError()`: `AppKitWindowException::engineReturnedNoLayer` by
default, overridden by the stage session.

# Staged window

`AppKitStagedWindow`[^stage] holds window, attachment, delegate.

* Content view is the engine surface; no native controls.
* `windowShouldClose:` → `closeRequested()`, answers false. Close asks:
  `stage.closed.<name>` mailed once, window stays; sketch decides.
* `windowDidResize:` → `nativeResized()`: content view frame in points,
  GL context `update()`, `resized(w, h, backingScaleFactor())`.
  Change-only in the abstract.[^abstract]
* `applyShow()` = `makeKeyAndOrderFront`. `destroyNative()`: delegate
  dropped (`setDelegate(0)`), both selectors `off()`'d, `orderOut`,
  `close`. Executor already released by the abstract.
* `off()` is mandatory: ext-appkit keeps each `on()` callable in a
  process-global table until `off()` or re-register. Skipping it pins the
  closed stage, its window and boxes for the process's life.

[^session]: src/Sessions/AppKitStageSession.php
[^stage]: src/Stages/AppKitStagedWindow.php
[^attach]: src/Views/AttachesAppKitEngines.php
[^abstract]: Surface\Stage\StagedWindow
