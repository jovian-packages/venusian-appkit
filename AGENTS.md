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

The OS bridge session and bare `NSWindow` provisioning exist. The 0.8 view
drivers were written against an older, opinionated `ext-appkit` and were torn
out; views inside the window come next.

`provisionNewWindow()` mints with the style mask
`TITLED|CLOSABLE|MINIATURIZABLE|RESIZABLE` and an origin of `(0, 0)` — the
screen's **bottom-left** under AppKit's coordinate space. Both are defaults
that shipped ahead of the decision, not settled policy. See
[`.okf/session.md`](.okf/session.md).

## Package rules (quick) — 0.8.x

- Composer: `jovian/venusian-appkit` **0.8.0**. PHP `^8.4|^8.5|^8.6`. macOS
  only. Requires `jovian/appkit`, `surface/bridge`, `surface/contracts`,
  `surface/native-windows`, `venusian-voyager/contracts`.
- Namespace root is `Jovian\Venusian\AppKit\` at `src/`.
- **The provider binds `mac.bridge`.** That container alias is the entire
  seam to Surface; installing this package is the whole of what makes macOS
  windowing available. Do not rename it.
- **Implement Surface's contracts, do not re-declare policy.** The abstract
  in `surface/bridge` owns guards, idempotency, and state. Fill the hooks.
- **Exceptions subclass `Surface\Contracts\Bridge\BridgeException`** so a
  sketch catches one type without naming AppKit.
- **Object parameters into `jovian/appkit` are `int` handles.** Pass
  `$obj->handle`; only returns and callback arguments come back boxed.
- **Never chain `->handle` off a temp.** PHP frees a method-call temp the
  moment `->handle` is read — before the outer call runs — the box's
  destructor releases the registry entry, and the ext resolves nil. Hold
  the box in a local through every call that uses its handle (and through
  any call using pointer bits derived from it, like `CGColor()`). This was
  the menu-bar Heisenbug and the invisible label styling; proven headless
  on 2026-08-30.
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
