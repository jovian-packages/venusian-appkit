---
type: Module
title: Enabled cascade
description: AppKit has no enabled state on a view, so this package carries a container's disabled state down to the controls inside it.
resource: src/UI/Concerns/
tags: [appkit, primitives, enabled]
status: draft
generated: { by: claude-opus/5.5, at: 2026-11-21T17:39:27Z }
sources:
  - id: down
    resource: src/UI/Concerns/CarriesEnabledDown.php
    title: CarriesEnabledDown
  - id: above
    resource: src/UI/Concerns/TakesInputFromAbove.php
    title: TakesInputFromAbove
---

# Why

Topside's `TKPrimitive::setEnabled()` stores each primitive's own flag and calls `applyEnabled()` on that primitive only. GTK, Qt and Raven pass a disabled container down to what is inside it themselves. AppKit cannot: `NSView` (and so `NSStackView`) has no enabled state; only `NSControl` does.[^down]

# Traits

| Trait | Used by | Does |
|---|---|---|
| `TakesInputFromAbove` | an AppKit primitive that is not a container | `applyEnabled()` calls `applyNativeEnabled($this->takesInput())`: false when it or any container above it is disabled. A control implements the hook as `NSControl::setEnabled()` |
| `CarriesEnabledDown` | an AppKit container | `applyEnabled()` asks each child that is enabled itself to work its state out again (a child disabled itself stays so, with everything inside it); `adopt()` does the same for a child added while a container above is disabled |

They are traits, not base classes, because an AppKit primitive already extends its Topside class (`TKButton`, `TKColumn`, ...).[^above]

# Checked

Not yet on any primitive in this package: there are no concrete AppKit primitives yet. The traits were checked through throwaway test classes written outside the package (an `NSView` container on `TKColumn` and `TKFixed`, an `NSButton` on `TKButton`), 11 checks, 2026-11-21: a disabled column disables the buttons in it and in a fixed inside it, leaves others alone, refuses `focus()` inside it; a button added later starts disabled; re-enabling restores each to its own flag.

[^down]: CarriesEnabledDown
[^above]: TakesInputFromAbove
