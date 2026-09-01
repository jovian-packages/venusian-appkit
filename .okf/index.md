---
okf_version: "0.2"
---

# jovian/venusian-appkit — knowledge bundle

The composition layer between `jovian/appkit` and `venusian/surface` on
macOS. This is where opinion is allowed: `jovian/appkit` may only project
`ext-appkit` one call at a time, and Surface may not know AppKit exists, so
everything that bundles AppKit calls into a policy lives here.

The OS bridge session and bare `NSWindow` provisioning exist so far.
Views inside the window come next.

Read this index first. Every concept here is `status: draft` until a human
verifies it.

# Concepts

* [session.md](/session.md) - the AppKit side of Surface's bridge lifecycle:
  what initialising, connecting, disconnecting and pumping each do, and the
  `NSWindow` factory hanging off it

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
| Requires | `jovian/appkit`, `surface/bridge`, `surface/contracts`, `surface/native-windows`, `venusian-voyager/contracts` |
| Container alias | binds `mac.bridge` |
