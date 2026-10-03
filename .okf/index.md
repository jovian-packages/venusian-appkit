---
okf_version: "0.2"
---

# jovian/venusian-appkit

* [Session](architecture/session.md) - NSApplication session: launch once, Dock presence on connect, event pump with budget, CFFileDescriptor wake.
* [Windows and menus](architecture/windows-and-menus.md) - NSWindow per name, delegate mail, content container and resize mail, one app-wide NSMenu bar, default bar, About panel.
* [Primitives](architecture/primitives.md) - Factory, shared native trait, stack/grid/fixed/scroll containers over Auto Layout, every leaf, layout model, mail.

# Runbooks

* [Testing](runbooks/testing.md) - Pest suite against the real toolkit in a workbench of path repos; macOS only.
