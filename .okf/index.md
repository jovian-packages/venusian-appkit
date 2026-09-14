---
okf_version: "0.2"
---

# jovian/venusian-appkit — knowledge bundle

The composition layer between `jovian/appkit` and `venusian/surface` on
macOS. This is where opinion is allowed: `jovian/appkit` may only project
`ext-appkit` one call at a time, and Surface may not know AppKit exists, so
everything that bundles AppKit calls into a policy lives here.

The OS bridge session, bare `NSWindow` provisioning, and the nineteen
Surface view twins exist — including `AppKitDatePicker` (`NSDatePicker`
clock-and-calendar, `NSDateFormatter` `yyyy-MM-dd`), `AppKitTable`
(`NSScrollView` + `NSTableView`, `NSIndexSet` selection), and
`AppKitGPUView` (plain `NSView` + adopted `CAMetalLayer`) plus
`AppKitGLView` / `AppKitGLSurface` (`NSOpenGLView`, context lent).

Read this index first. Every concept here is `status: draft` until a human
verifies it.

# Concepts

* [session.md](/session.md) - the AppKit side of Surface's bridge lifecycle:
  what initialising, connecting, disconnecting and pumping each do, and the
  `NSWindow` factory hanging off it
* [gpu-view.md](/gpu-view.md) - GPU hosts: adopt a Metal layer, or lend
  an `NSOpenGLContext` via `AppKitGLSurface`; `mintGPU()` branches on
  `SurfaceKind`

# Related bundles

* [jovian/appkit](https://github.com/jovian/appkit) - the typed projection
  this package composes
* [venusian/surface](https://github.com/VenusianPHP/surface) - the
  cross-platform abstraction this package plugs into

# Fast facts

| | |
|---|---|
| Version | 0.8.0, PHP `^8.4\|^8.5\|^8.6`, macOS only |
| Namespace | `Jovian\Venusian\AppKit\` at `src/` |
| Requires | `jovian/appkit`, `surface/bridge`, `surface/contracts`, `surface/drawing`, `surface/native-windows`, `venusian-voyager/contracts` |
| Container alias | binds `mac.bridge` |
