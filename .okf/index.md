---
okf_version: "0.2"
---

# jovian/venusian-appkit

* [Session](architecture/session.md) - NSApplication launched once, Dock presence while connected, an event pump with a budget, and a CFFileDescriptor wake.
* [Window](architecture/window.md) - An ObjCKeyWindow with an NSWindowDelegate wired to Topside's window lifecycle; resizes and backing-scale changes from the delegate.
* [Menu bar](architecture/menu-bar.md) - One NSMenu per window and one for none key: natives, enabled state, bare keys, and what macOS adds.
* [UI primitives](architecture/ui-primitives.md) - The AppKit column, row, fixed, label and button on Topside's TK classes, and the native widget each one is.
* [Enabled cascade](architecture/enabled-cascade.md) - AppKit has no enabled state on a view, so this package carries a container's disabled state down to the controls inside it.

# Runbooks

* [Testing](runbooks/testing.md) - Pest suite against the real toolkit in a workbench of path repos; macOS only.

# Deprecated

* [Windows and menus](architecture/windows-and-menus.md) - the bridge-era window, menu bar, focus and keys (deprecated2/).
* [Staged windows](architecture/staged-windows.md) - AppkitStagedWindow (deprecated/Windows/).
* [Primitives](architecture/primitives.md) - the bridge-era AppKit primitives (deprecated2/Primitives/).
* [Input](architecture/input.md) - AppkitInputEngine and GameControllerPadSource (deprecated/, deprecated2/).
