---
type: Module
title: UI primitives
description: The AppKit column, row, fixed, label, button, check box, switch and dropdown on Topside's TK classes, and the native widget each one is.
resource: src/UI/
tags: [appkit, primitives]
status: draft
generated: { by: claude-opus/5.5, at: 2026-11-21T18:58:43Z }
sources:
  - id: native
    resource: src/UI/Concerns/AppkitNative.php
    title: AppkitNative
  - id: box
    resource: src/UI/Concerns/AppkitStackChildren.php
    title: AppkitStackChildren
---

# Classes

Each extends Topside's class (`TKColumn`, `TKRow`, `TKFixed`, `TKLabel`, `TKButton`, `TKCheckbox`, `TKToggle`, `TKDropdown`, `TKTextInput`, `TKComboBox`, `TKDatepicker`, `TKLink`, `TKSeparator`, `TKProgressBar`, `TKSpinner`, `TKSlider`, `TKToggleButton`, `TKSegmentedControl`, `TKImage`, `TKScrollView`, `TKTabView`, `TKSplitView`, `TKGrid`, `TKTable`, `TKTextArea`, `TKListView`, `TKCollectionView`, `TKTree`) and fills in its hooks; the driver's `$primitives` map names them. Fonts and alignment of what shows text are in `AppkitText`. Shared native work is in `AppkitNative`, what column and row share in `AppkitStackChildren`.[^native]

| Class | Native |
|---|---|
| `AppkitColumn`, `AppkitRow` | `NSStackView` with spacing and edge insets; a child's alignment as constraints, its fill as hugging priority and the distribution, re-settled when a child is shown or hidden |
| `AppkitFixed` | plain `NSView`: children framed with `setFrame`, top-left based against the unflipped view, re-framed when it resizes; the larger of frame and minimum; its floor covers every frame |
| `AppkitLabel` | `NSTextField::labelWithString`, left-aligned at build; colour null restores the colour read at build |
| `AppkitButton` | `NSButton` with an `ObjCTarget` (held weakly) that calls `clicked()`; in the Tab order only with Full Keyboard Access on, as macOS does |
| `AppkitCheckbox` | `NSButton::checkboxWithTitleTargetAction`; its action (the user's toggle only) reports the new state |
| `AppkitToggle` | `NSSwitch`; its action reports the new state |
| `AppkitDropdown` | pop-up `NSPopUpButton`, options added as menu items so repeated titles stay separate; its action reports the chosen index |
| `AppkitTextInput` | `NSTextField` / `NSSecureTextField`, at least 120 wide; Return is the delegate's `insertNewline:` (the action also fires when editing ends) |
| `AppkitComboBox` | `NSComboBox`, completions in `Support/AppkitCompletionList` (a child window that never becomes key); typed text deselects the option |
| `AppkitDatepicker` | `NSDatePicker`, field and stepper (no calendar popup: ext-appkit has no NSPopover) |
| `AppkitLink` | label with `NSLinkAttributeName`, selectable; not selectable while disabled |
| `AppkitSeparator` | `NSBox` separator, one point |
| `AppkitProgressBar` | `NSProgressIndicator` bar; at least 120 wide at priority 490, over the stack's pull to zero |
| `AppkitSpinner` | spinning `NSProgressIndicator`, not drawn while stopped |
| `AppkitSlider` | continuous `NSSlider`; 120 wide as the progress bar is |
| `AppkitToggleButton` | push-on, push-off `NSButton` |
| `AppkitSegmentedControl` | `NSSegmentedControl`, select-one |
| `AppkitImage` | layer-backed view (`NSImageView` cannot cover); natural size at priority 485, a FIT height set again as the width changes (no aspect constraint is bound) |
| `AppkitScrollView` | `NSScrollView`; the document pinned to the clip view's top, at least its size along what scrolls |
| `AppkitTabView` | `NSTabView`, pages pinned inside their items; kept at least strip and largest page at priority 488; a disabled one refuses the click in `shouldSelectTabViewItem` |
| `AppkitSplitView` | `NSSplitView`; the divider is a width or height constraint at 489 (under Auto Layout `setPositionOfDividerAtIndex` lasts only until the next layout), updated through a drag |
| `AppkitGrid` | `NSGridView` in a plain view, built again when cells change (merged cells cannot be unmerged); a FILL child hugs at 250, or 1 where its line expands (at 750 a spanning button held every container above to its width) |
| `AppkitTable` | cell-based `NSTableView` in a scroll view (`ScrollsInside`: focus and least size) |
| `AppkitTextArea` | `NSTextView` on TextKit 1 in its scroll view; styles as storage attributes |
| `AppkitListView` | view-based `NSTableView`; Return and double click activate |
| `AppkitCollectionView` | `NSCollectionView` flow layout; a click activates |
| `AppkitTree` | `NSOutlineView`; descendants AppKit closes and reopens with their ancestor are told from the user's |

# Notes

* Resizing the window and dragging an `NSSlider` hold the pump as a divider does (measured by hand 2026-11-22: up to 4.6 s, and 649 to 1038 ms).
* **A drag is AppKit's own loop.** The mouse-down's `sendEvent:` returns at release (measured 2026-11-22 on a divider: 664 ms, no pump return), and event monitors see none of it (`NSEvent.h`: "consumed by nested event-tracking loops"). Native callbacks do run inside it: notifications, actions, timers in the common modes (`NSSplitViewDidResizeSubviews`: 21 calls in that drag). So a primitive hears a drag and keeps its value, but the mail waits for release: the event loop cannot be turned from in there (`EventLoop::turn()` sleeps by pumping the toolkit, which would take AppKit's drag events). Read the tracked event with `currentEvent()`; `pressedMouseButtons()` is the hardware's, 0 for posted events. ext-appkit binds no `NSSplitView::setDelegate`.
* Enabled: [the enabled cascade](/architecture/enabled-cascade.md): containers use `CarriesEnabledDown`, labels and buttons `TakesInputFromAbove` (`NSControl::setEnabled`).
* A stack pulls its edges in to its children at hugging 740, just under a child's own 750: a START or END child keeps its natural size across the axis. At 750 the two tied and AppKit stretched the child (seen with a START button in a column, 2026-11-21).
* Opacity `alphaValue`; background and corner radius on the view's layer, `masksToBounds` off (content is not clipped); minimum size as `>=` constraints; `size()` the frame.
* Transitions: the fade only, through `NSAnimationContext`, landing on a one-shot `NSTimer`. The 24 pt slide is skipped: an `NSStackView` positions its arranged views itself.
* `nativeFocus()` reads the first responder back (`makeFirstResponder`'s return value is not reliable). `hasFocus()`: the view, or the field editor whose delegate it is, is its window's first responder.

# Checked

List, collection and tree (2026-11-22, a scratch harness, NTS and ZTS, and by screenshot): code changes post nothing; select, open, close and activate post; disabled items post nothing; removal. All sixteen primitives of that day's three batches also render from one Sharp file.

Table and text area (2026-11-22, a scratch harness, NTS and ZTS): fill, focus, code changes post nothing, the user's selection and typing post, line selection, scroll to a line, removal.

Scroll view, tab view, split view and grid (2026-11-22, a scratch harness in a real window, untouched, NTS and ZTS): sizes, fill through a plain row, code changes post nothing, tab choice, removal. The divider dragged through AppKit's own tracking loop by posted events: one `SplitMoved` with the last position, and the pane stays there.

Separator, progress bar, spinner, slider, toggle button, segmented control and image (2026-11-22, a scratch harness, 25 checks in a real window on NTS and ZTS): sizes in a column and a row; code changes post nothing; the user's slide, press and choice post their mail; disabled posts nothing; PNG and SVG show, another file is refused; removal. Looked at in a screenshot.

Text input, combo box, date picker and link (2026-11-21, a scratch real-window harness, 30 checks, active and inactive window): code changes post nothing; typing, Return, option, completion and pick post one mail each; completions keep focus and hide on Esc, choice and focus leaving; disabled posts nothing; removal. The link is not clicked.

Check box, switch and dropdown (2026-11-21, a scratch real-window harness, 11 checks): changes from code post nothing; the user's toggle, flip and choice each post one mail with the new value; new options with a selection post nothing; nothing from inside a disabled column; removal. Driven by performClick and, for the popup, selectItemAtIndex plus sendActionTo.

The real-window harness (a scratch topside-dev, 35 checks) through these classes passes: building, natives inside their containers, fixed frames, label text, alignment and font, a native click posting `ButtonClicked`, the enabled cascade, `focus()` moving between two buttons with `hasFocus()`, reordering, transitions, text colour, removal, close. Commit 6eb1d79.

[^native]: AppkitNative
