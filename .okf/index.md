---
okf_version: "0.2"
---

# jovian/venusian-appkit

* [Session](architecture/session.md) - NSApplication session: launch once, Dock presence on connect, event pump with budget, CFFileDescriptor wake.
* [Windows and menus](architecture/windows-and-menus.md) - NSWindow per name, delegate mail, content container and resize mail, one app-wide NSMenu bar, default bar, About panel.
* [Staged windows](architecture/staged-windows.md) - AppkitStagedWindow: ObjCStageWindow + ObjCDrawView, partial present, Spaces and exclusive fullscreen, displays, capabilities, lending.
* [Primitives](architecture/primitives.md) - Factory, shared native trait, stack/grid/fixed/scroll containers over Auto Layout, every leaf, layout model, mail.
* [Input](architecture/input.md) - AppkitInputEngine taps the session, reads keys by position, the pointer under any window, the wheel as SDL; GameControllerPadSource lists gc- pads, follows focus or refuses where macOS keeps pads from a background process.

# Runbooks

* [Testing](runbooks/testing.md) - Pest suite against the real toolkit in a workbench of path repos; macOS only.
