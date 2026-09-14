# Agent guidelines — jovian/venusian-appkit

## Knowledge Bundle (OKF)

This package ships an Open Knowledge Format bundle at [`.okf/`](.okf/)
(excluded from the Composer dist via `.gitattributes` `export-ignore`).
Before changing code or advising on this package: read
[`.okf/index.md`](.okf/index.md) first, open only the concepts the task
needs, prefer `status: stable` over `draft`. When you learn something
durable, update the affected concept(s) and append [`.okf/log.md`](.okf/log.md);
new or changed concepts stay `status: draft` until a human verifies them.

Do **not** create `.okf` folders under `src/Sessions` or any other component
tree — knowledge for this package lives at the package root only.

## Where this package sits

`ext-appkit` (1:1 binding, zero opinion) → `jovian/appkit` (enums + typed
projection) → **`jovian/venusian-appkit`** (composition) → `venusian/surface`
(cross-platform abstraction).

**This is the layer where opinion is allowed.** `jovian/appkit` may only
project one extension call per method, and Surface may not know AppKit
exists, so everything that bundles AppKit calls into a policy belongs here.

Never depend on `jovian/gtk` or `jovian/venusian-gtk`, and never build a
cross-platform abstraction here — that is Surface's job, and the two engine
packages are shape-parallel by design and share no code.

## Current state

The OS bridge session, bare `NSWindow` provisioning, the nineteen Surface
view twins, `AppKitGPUView` (layer) and `AppKitGLView` + `AppKitGLSurface` (OpenGL) exist. GPU layers are adopted on this side
only — see [`.okf/gpu-view.md`](.okf/gpu-view.md).

`provisionNewWindow()` mints with the style mask
`TITLED|CLOSABLE|MINIATURIZABLE|RESIZABLE` and an origin of `(0, 0)` — the
screen's **bottom-left** under AppKit's coordinate space. Both are defaults
that shipped ahead of the decision, not settled policy. See
[`.okf/session.md`](.okf/session.md).

## Package rules (quick) — 0.8.x

- Composer: `jovian/venusian-appkit` **0.8.0**. PHP `^8.4|^8.5|^8.6`. macOS
  only. Requires `jovian/appkit`, `surface/bridge`, `surface/contracts`,
  `surface/drawing`, `surface/native-windows`, `surface/stage`,
  `venusian-voyager/contracts`.
- Namespace root is `Jovian\Venusian\AppKit\` at `src/`.
- **The provider binds `mac.bridge` and `stage.appkit`.** Those container
  aliases are the entire seam to Surface; installing this package is the
  whole of what makes macOS windowing and AppKit stages available. Do not
  rename them.
- **Implement Surface's contracts, do not re-declare policy.** The abstract
  in `surface/bridge` owns guards, idempotency, and state. Fill the hooks.
- **Exceptions subclass `Surface\Contracts\Bridge\BridgeException`** so a
  sketch catches one type without naming AppKit. Stage-host failures are
  `AppKitStageException extends Surface\Contracts\Stage\StageException`.
- **Object parameters into `jovian/appkit` are `int` handles.** Pass
  `$obj->handle`; only returns and callback arguments come back boxed.
- **Never chain `->handle` off a temp.** PHP frees a method-call temp the
  moment `->handle` is read — before the outer call runs — the box's
  destructor releases the registry entry, and the ext resolves nil. Hold
  the box in a local through every call that uses its handle (and through
  any call using pointer bits derived from it, like `CGColor()`). This was
  the menu-bar Heisenbug and the invisible label styling; proven headless
  on 2026-08-30.
- **Adopt GPU layers on this side only.** venusian-metal hands pointer bits
  and a class name (`'CAMetalLayer'`); this package calls
  `Jovian\Bindings\AppKit\Runtime\Bridge::adopt` then `ObjCObject::box`.
  Never import a `Jovian\Bindings\Metal` or `Jovian\Venusian\Metal` symbol
  — the only crossing is `adopt(pointer bits)`. Each side owns one retain.
- **Hold the GPU boxes.** The adopted layer box and the host `NSView` box
  live on `AppKitGPUView` for the view's life. Same temp-box rule as
  above. `applyFrame` resizes the executor in **pixels**
  (`round(w × backingScaleFactor())`), not points.
- **A GL surface lends its context and never draws.** `AppKitGLSurface`
  holds the `NSOpenGLView`, `NSOpenGLPixelFormat` and `NSOpenGLContext`
  boxes and answers `makeCurrent()` / `present()` / `drawableSize()`. The
  twin owns placement, the surface owns the context, the engine's executor
  owns GL state. Never import `Jovian\Bindings\OpenGL` or
  `Jovian\Venusian\OpenGL`. Mint order is native → surface → `GPUHost->gl`
  → `attach()` → twin, because `GPUView`'s constructor takes the executor.
- **`attachEngine()` decides by `SurfaceKind`, not by engine name.**
  `AttachesAppKitEngines` is the one attach path for GPU regions
  (`mintGPU()`) and stages. `LAYER` adopts; `GL_CONTEXT` lends;
  `VULKAN_SURFACE` / `HOST_WINDOW` answer null and the caller refuses. The
  enum is the whole decision.
- **`NS_OPTIONS` values stay `int`** because PHP enums cannot be OR'd. Build
  them from `SomeEnum::CASE->value | ...`.
- **`setReleasedWhenClosed(false)` on every window.** AppKit would otherwise
  free the window on close while `jovian/appkit`'s registry still holds the
  handle, and the next touch is a use-after-free. The consequence is that a
  user closing a window only *hides* it — the handle stays good and
  `isVisible()` goes false. GTK is the opposite; do not assume symmetry.
- **Respect the runtime's shutdown posture.** `jovian/appkit` deliberately
  stops releasing handles once interpreter shutdown begins, because
  releasing after `NSApp` teardown crashes. Do not add teardown that fights
  it.
- Enums are int- or string-backed with FULLY UPPERCASE cases. **No class
  constants anywhere.** Prefer `is_null($var)` over `$var === null`.

## Verification

Needs a Mac with `ext-appkit` loaded. Pure-logic code should be covered by
Pest with no extension present; anything that touches AppKit is proven by
running it, not by a skipped test reporting success.

The standing acceptance check for the bridge: connect raises a Dock icon
with no window, disconnect drops it, the process exits cleanly.
