---
type: Module
title: UI primitives
description: The AppKit column, row, fixed, label, button, check box, switch and dropdown on Topside's TK classes, and the native widget each one is.
resource: src/UI/
tags: [appkit, primitives]
status: draft
generated: { by: claude-opus/5.5, at: 2026-11-25T21:00:00Z }
sources:
  - id: native
    resource: src/UI/Concerns/AppkitNative.php
    title: AppkitNative
  - id: box
    resource: src/UI/Concerns/AppkitStackChildren.php
    title: AppkitStackChildren
  - id: pointer
    resource: src/UI/Support/AppkitPointer.php
    title: AppkitPointer
  - id: overlay
    resource: src/UI/AppkitOverlay.php
    title: AppkitOverlay
---

# Classes

Each extends Topside's class (`TKColumn`, `TKRow`, `TKFixed`, `TKLabel`, `TKButton`, `TKCheckbox`, `TKToggle`, `TKDropdown`, `TKTextInput`, `TKComboBox`, `TKDatepicker`, `TKLink`, `TKSeparator`, `TKProgressBar`, `TKSpinner`, `TKSlider`, `TKToggleButton`, `TKSegmentedControl`, `TKImage`, `TKScrollView`, `TKTabView`, `TKSplitView`, `TKGrid`, `TKTable`, `TKTextArea`, `TKListView`, `TKCollectionView`, `TKTree`, `TKOverlay`) and fills in its hooks; the driver's `$primitives` map names them. Fonts and alignment of what shows text are in `AppkitText`. Shared native work is in `AppkitNative`, what column and row share in `AppkitStackChildren`.[^native]

| Class | Native |
|---|---|
| `AppkitColumn`, `AppkitRow` | `NSStackView` with spacing and edge insets; a child's alignment as constraints, its fill as hugging priority and the distribution, re-settled when a child is shown or hidden |
| `AppkitFixed` | plain `NSView`: children framed with `setFrame`, top-left based against the unflipped view, re-framed when it resizes; the larger of frame and minimum; its floor covers every frame |
| `AppkitLabel` | `NSTextField::labelWithString`, left-aligned at build; colour null restores the colour read at build |
| `AppkitButton` | `NSButton` with an `ObjCTarget` (held weakly) that calls `clicked()`; in the Tab order only with Full Keyboard Access on, as macOS does; style, colour, icon and role below |
| `AppkitCheckbox` | `NSButton::checkboxWithTitleTargetAction`; its action (the user's toggle only) reports the new state |
| `AppkitToggle` | `NSSwitch`; its action reports the new state |
| `AppkitDropdown` | pop-up `NSPopUpButton`, options added as menu items so repeated titles stay separate; its action reports the chosen index |
| `AppkitTextInput` | `NSTextField` / `NSSecureTextField` / `NSSearchField` (search), at least 120 wide; Return is the delegate's `insertNewline:` (the action also fires when editing ends) |
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
| `AppkitOverlay` | layer-backed plain `NSView`, children as subviews in order (the first at the back). Each child placed on both axes by its Align, or FILL where it fills, inside padding and margin, with the stack's constraints (`AppkitPlacesChildren`); see below |

# Look and layout settings

Topside's optional hooks are overridden only where AppKit shows the setting itself; the rest stay empty, and the getter still answers what was set.[^native]

| Setting | What AppKit shows |
|---|---|
| Padding (column, row, tab page) | Per side: `NSStackView` edge insets along the axis, the constants of each child's constraints across it; `applyPadding()` sets both again live |
| Padding (grid) | Per side: the constants pinning the `NSGridView` inside its plain view |
| Margin in a column or row | Across the axis: added to the padding in the child's constraints (CENTER centres in the room the two sides leave). Between shown children: `setCustomSpacingAfterView` = spacing + the margins facing each other. Before the first and after the last shown child: added to the padding in the edge insets. Recomputed on attach, detach, show, hide, reorder, spacing and padding. Left and right are leading and trailing in a left-to-right layout |
| Margin in a fixed | The frame moves in by the left and top margin; the fixed's floor reaches past the right and bottom margin |
| Margin of a tab page | The constants of the page's pins inside its item's view; the tab view's least size counts it |
| Tooltip | `NSView::setToolTip` |
| Border | The layer's `borderWidth` and `borderColor`, inside the bounds; a null colour is `NSColor::separatorColor`, the system line colour, resolved when the border is set (a layer keeps a CGColor, which does not follow a later appearance change). Width 0 takes it away |
| Cursor | `AppkitPointer`: an `NSTrackingArea` (cursor update, enter and exit, visible rect, active app) owned by the view, one shared local monitor. A cursor update or enter with the pointer inside sets the `NSCursor` (the update is taken, so the owner's `cursorUpdate:` does not reset it); the update on leaving goes to AppKit, which shows its arrow again. ARROW arrow, POINTER pointing hand, TEXT I-beam, CROSSHAIR crosshair, GRAB open hand, GRABBING closed hand, NOT_ALLOWED operation-not-allowed, RESIZE_HORIZONTAL / RESIZE_VERTICAL resize left-right / up-down, RESIZE_NWSE / RESIZE_NESW `frameResizeCursorFromPositionInDirections` top-left / top-right, all directions. A text field shows a cursor set on it while not editing |
| Button style | DEFAULT bordered; BORDERLESS and LINK `setBordered(false)` (no bezel inset: `size()`, the frame, is then the title and icon); LINK's title in `NSColor::linkColor`, its pointing hand through the cursor |
| Button text colour | An attributed title with `NSForegroundColorAttributeName`, the button's font and alignment, plus `contentTintColor` (colours a symbol icon too); no colour writes the plain title again. Every label, style, font, colour and alignment change writes the title, so `setTitle` never drops the colour |
| Button font, alignment | `NSControl::setFont`, `setAlignment` |
| Button icon | `NSImage::initWithContentsOfFile` (a file wins when AppKit reads it), else `imageWithSystemSymbolNameAccessibilityDescription`; `IMAGE_LEADING`; none `NO_IMAGE` |
| Button role | Key equivalent: DEFAULT Return (AppKit draws it as the default button), CANCEL Esc, none empty |
| Search field | `NSSearchField::initWithFrame`, the same delegate as the plain field. With a minimum height at least the large control's (30, measured on a large field; regular is 22) it is `NSControlSize::LARGE` (ext-appkit f6a12a4), else REGULAR. The bezel fills a taller frame and the text stays centred (at 32 high the field editor is 16 high at y=8) |
| Borderless search field | Keeps its bezel, which is its rounded fill, and loses the focus ring (`NSFocusRingType::NONE`); false puts the ring back |
| Borderless field (plain or secret) | `setBordered(false)`, `setBezeled(false)`, no background drawn, `NSFocusRingType::NONE`; false puts back the bezel (or border) it was built with, its ring and background. Turning bezel or border on or off sets the other off (observed), so only the built one is turned on |
| Field background | A plain field with its frame: its own `backgroundColor` (square). A borderless field, and any search field (NSSearchField draws no `backgroundColor`, observed): the layer's background, which the corner radius rounds. It moves between the two when borderless changes |
| Read-only field | `setEditable(false)` (still selectable) |

Left unshown on AppKit:

| Setting | Why |
|---|---|
| `Cursor::MOVE` | AppKit has no move cursor |
| Margin in a grid | NSGridView's own space is a whole row's or column's padding, which moves every cell in that line |
| Margin in a scroll view | NSScrollView's content insets are not it. Measured 2026-11-24 in a real window, margin 20 50 40 30 (top left bottom right) on a column in a 400x300 scroll view: the vertical scroller went from 16x300 at the edge to 16x240, 30 in, so the insets move the scrollers too; the pinned document stayed the clip view's width and ran 50 past the right edge; and its top sat 40 above the view instead of 20 below (lines 0 and 1 hidden in the screenshot) |
| Margin in a split view, window content | A split view frames its panes itself; the window's content column has no container to keep it |
| A frameless search field | Without its bezel an NSSearchField still draws its placeholder past the search button, but the field editor it edits in covers the whole field: the text ran under the magnifier ("S🔍arch") and sat at the top of a taller field. Measured 2026-11-24 at 32 high: the field editor is at x=0, y=0, 32 high with no bezel; x=26, y=8, 16 high with it. The cell answers the right rects (`searchTextRectForBounds:` x=26) and does not use them; no property changes it, so a search field keeps its bezel |
| A plain borderless field taller than its text | NSTextField draws its text at the top; it has no vertical alignment. A row that centres the field (its natural height) does it |
| Text input maximum length | An NSTextField has no limit of its own: it takes one only through a formatter, and no `NSFormatter` is bound |
| Link text colour | AppKit draws a link in its own link colour over `NSForegroundColorAttributeName` (a magenta foreground drew in the link colour, 2026-11-24); the field editor's `linkTextAttributes` are not bound |

# Overlay

* **Z-order** is subview order: AppKit draws later subviews above. `moveTo()` re-adds the moved child and every child after it with `addSubview:` (only that raises a view; `addSubview:positioned:relativeTo:` is not bound), so each is above those before it; removal drops their constraints, which are made again, and a focus inside a re-added child is given back. A child on top takes the click wherever it covers (checked with `hitTest:`).
* **Sizing**: a child sizes the overlay through its placement constraints, as a child sizes a stack across its axis; the overlay's pull to zero comes from the container above. A child that fills both ways (`sizesOverlay()` false) is held to the four edges with its compression resistance at 1 (restored when it stops covering), and an `AppkitImage` there drops its 485 natural-size constraints, so it never sizes the overlay: a 3200x2000 picture in an overlay with `minSize(0, 320)` leaves it 320 high and the window its size; its fitting size is its minimum height and its other children's width. Topside's `expands()` leaves such a child out (951f8b1), so the overlay keeps its height in a column with spare room; the stack reads `expands()` rather than walking children itself.
* **Hidden children** have no constraints while hidden, so they size nothing; their frame is AppKit's to leave meanwhile, and they are placed again when shown.
* `ImageScaling::FILL` in an overlay covers and crops: on a 620x320 overlay a 3200x2000 picture shows a 43.5-point top band where cover-and-crop predicts 43.8 and stretching 64.

# Clickable groups

* `applyClickable()` (every group, in `AppkitNative`): an `NSClickGestureRecognizer` on the group's view, primary button, one click, `delaysPrimaryMouseButtonEvents` off, with an `ObjCTarget` the primitive keeps (AppKit does not retain a recognizer's target); off and at release it is removed. Its action reads `locationInView:` and calls `groupClicked()` with x, y from the top-left: an unflipped view's y is its height less AppKit's.
* What AppKit does, measured with posted clicks: a click on the group or on a label or image in it is the group's; a button in it keeps its click (`ButtonClicked` only), and a text field takes its own (nothing posted): with the press not delayed, the control's own tracking takes the release, and the recognizer never sees it. With the press delayed (a click recognizer's default) the recognizer would take a button's click too. A clickable group inside a clickable group takes the click alone (AppKit lets one recognizer act). Disabled: nothing (Topside's check).
* Checked on a column, an overlay and a scroll view (flipped).

# Moves

* `MOVE_RIGHT`, `MOVE_LEFT`, `MOVE_TOP`, `MOVE_BOTTOM` play only for a child of an overlay, which keeps the child's constraints and their own constants (`bases`). While the move runs the overlay holds the child: placed even while hidden, its constraint constants offset by the move, and neither its visibility change nor a layout change puts it back.
* Appearing: shown at full opacity, placed, laid out (its size), offset one own width or height toward the edge and laid out again; one frame later an `NSAnimationContext` group (duration `$seconds`, `allowsImplicitAnimation`, timing function `CAMediaTimingFunction::functionWithControlPoints()` on Topside's `curve(true)`, `MOVE_IN_CURVE` [0.22, 1, 0.36, 1], easeOutQuint) sets the offsets to 0 and lays out, and AppKit animates the frames. Disappearing: the group, on `curve(false)` (`MOVE_OUT_CURVE` [0.64, 0, 0.78, 0], easeInQuint, the same motion backwards), offsets it out to the edge, and the landing timer hides it and lets the overlay place it as it is (hidden: unplaced). No fade either way. A new `setVisible()` mid-move stops the move and places the child, then plays from there.
* Measured on a 620-point full-overlay drawer over 1 s, against the curve over each capture's time span: appearing, 0.91 to 0.93 of the way in at 0.30–0.46 s (the curve 0.80–0.93; plain easeOut would be 0.41–0.60), 0.99 at 0.6–0.74 s, landed by 0.9 s; disappearing, 0.02 out at 0.31–0.46 s, 0.18 to 0.21 at 0.6–0.76 s (plain easeIn 0.44–0.62), 0.57 to 0.62 at 0.81–0.92 s, then hidden. FADE and SLIDE keep AppKit's own curve: the fade's presentation opacity reads 0.06, 0.26, 0.5, 0.67 … 1 every 0.1 s, as before. LEFT, TOP and BOTTOM come from their sides. A hidden drawer takes no clicks, and a page's click and cursor under it work again.
* Unshown: a move anywhere else (column, row, grid, fixed, scroll view, tab view, split view): the container positions the view itself, and an offset frame would be put back by the next layout pass, so the view is shown or hidden at once.

# Cursor under a covering view

`AppkitPointer` sets a view's cursor only when the window's content view hit-tests the pointer to that view or something inside it: AppKit fires a tracking area per view, so a page's pointing hand showed through a drawer above it. Checked: the page's pointer cursor does not show over the shown drawer, and shows again once the drawer is hidden.

# Corners and clip

* `applyCorners()`: the layer's `cornerRadius` is the largest radius, `maskedCorners` the corners with a radius (all four when uniform). Core Animation takes one radius, so corners with different non-zero radii are all rounded with the largest. The mask follows the view's geometry: in an unflipped view (plain view, overlay, column, row) the top corners are MaxY, in a flipped one (label, button, text input, slider, scroll view, tab view, text area) MinY. Checked in pixels on both: `'0 0 14 14'` is square on top and rounded at the bottom.
* `applyClip()` sets the view's `clipsToBounds` (ext-appkit a984583), which the layer's `masksToBounds` follows. AppKit turns `clipsToBounds` on for an NSView or NSStackView once its layer has a corner radius (not for an NSTextField), and over a `masksToBounds` set on the layer, so every layer change sets it again from `clip()`: an unclipped column stays unclipped after its corners are set, a clipped one stays clipped through a radius change. An NSStackView's own clipping default is turned off the same way.
* `AppkitImage` always clips (a covering picture is larger than its view), whatever `clip()` says; its corners as any view's.

# The line round a search field

A bezeled NSSearchField on this machine has a bright one-point line round it (#c8c8c8 above, #dddddd below, on a #2a2a2c column; fill #353537). It is macOS's Increase Contrast setting (`com.apple.universalaccess increaseContrast` = 1), not the adapter: a stock field in a Swift AppKit window has it in every variant tried (regular and large, layer-backed or not, inside an `NSVisualEffectView` sidebar, square bezel, explicit dark appearance, each `backgroundStyle`), and the same process started with `-increaseContrast 0` draws the bezel without it (half-point #454547 above, #616162 below, fill unchanged). Nothing is done about it here: it is the user's setting.

The Mac App Store's sidebar field has no such line under the same setting because it is not an NSSearchField bezel. Accessibility shows a group, 200x28, holding a search text field, 142x30, 31 in from the group's edge, with no children; a stock NSSearchField is one element with its search button as a child. Its pixels: fill #333333 on #282828 with a one-pixel #3d3d3d line. The same structure from Topside's primitives gives that look, with no bezel to outline: a row with a background, corner radius, border and minimum height, a borderless button with the `magnifyingglass` symbol, and a plain borderless text input, both centred across the row (seen: 28 high, the field 16 high at its centre, fill #353537, line #4a4a4b, on #2a2a2c).

# Notes

* Resizing the window and dragging an `NSSlider` hold the pump as a divider does (measured by hand 2026-11-22: up to 4.6 s, and 649 to 1038 ms).
* **A drag is AppKit's own loop.** The mouse-down's `sendEvent:` returns at release (measured 2026-11-22 on a divider: 664 ms, no pump return), and event monitors see none of it (`NSEvent.h`: "consumed by nested event-tracking loops"). Native callbacks do run inside it: notifications, actions, timers in the common modes (`NSSplitViewDidResizeSubviews`: 21 calls in that drag). So a primitive hears a drag and keeps its value, but the mail waits for release: the event loop cannot be turned from in there (`EventLoop::turn()` sleeps by pumping the toolkit, which would take AppKit's drag events). Read the tracked event with `currentEvent()`; `pressedMouseButtons()` is the hardware's, 0 for posted events. ext-appkit binds no `NSSplitView::setDelegate`.
* Enabled: [the enabled cascade](/architecture/enabled-cascade.md): containers use `CarriesEnabledDown`, labels and buttons `TakesInputFromAbove` (`NSControl::setEnabled`).
* A stack pulls its edges in to its children at hugging 740, just under a child's own 750: a START or END child keeps its natural size across the axis. At 750 the two tied and AppKit stretched the child (seen with a START button in a column, 2026-11-21).
* Opacity `alphaValue`; background and corners on the view's layer, content clipped only with `clip()` (see Corners and clip); minimum size as `>=` constraints; `size()` the frame.
* Transitions: the fade through `NSAnimationContext`, landing on a one-shot `NSTimer`. The 24 pt slide is skipped: an `NSStackView` positions its arranged views itself. A `MOVE_*` plays in an overlay (see Moves); elsewhere it shows or hides at once.
* `nativeFocus()` reads the first responder back (`makeFirstResponder`'s return value is not reliable). `hasFocus()`: the view, or the field editor whose delegate it is, is its window's first responder.

# Checked

Move curve (2026-11-25, a scratch real-window harness, 14 checks, all passing on NTS and ZTS; Pest 223 on both): Topside's two curves; a 1 s MOVE_RIGHT in and out sampled in pixels three times each against the Bézier and against plain easeOut and easeIn; landing and hiding; FADE and SLIDE as before.

Clickable groups and moves (2026-11-25, a scratch real-window harness, 38 checks with pixels and posted clicks, all passing on NTS and ZTS; Pest 223 on both): the recognizer's setup; group, label, image, button, text field, nested and disabled clicks with x, y; the drawer's move in, landing, move out, hiding, the page clickable and its cursor under it; the other three edges; a move in a column shown at once; FADE's presentation opacity rising over its second and SLIDE fading in place as before.

Overlay, corners and clip (2026-11-25, a scratch real-window harness, 39 checks, all passing on NTS and ZTS; Pest 223 on both): the stack's placement after the shared refactor; subview order; the window and the overlay's fitting size with a 3200x2000 picture; the covering picture's frame and no natural size; child placement; corners and clip in pixels on unflipped and flipped views; FILL's cover and crop in pixels; clip through radius changes and toggles; z-order by `hitTest:`; hidden and covering children sizing nothing; padding live; the natural width; focus kept through a reorder; the hero case (320 high with spare room).

Search field look (2026-11-24, 101 checks on NTS and ZTS; the hover checks fail in runs where the user's own pointer or another window gets in the way, and pass alone): a search field is the regular control, large from a minimum height of 30 and regular again below; at 32 high it is 32 high and edits centred, right of its button; borderless keeps the bezel and drops the focus ring, false restores it; a plain field stays regular. Pixels as above, with and without `-increaseContrast 0`. Pest: 223 passed on NTS and ZTS.

Follow-up (2026-11-24, the harness rebuilt, 93 checks, all passing on NTS and ZTS, hover checks skipped only when another app holds activation, none were): a null border colour is the separator colour; the window's content column takes Insets padding (topside 9d9e6f1, no adapter change); a scroll view's content insets are left alone for a document margin; a borderless search field keeps its bezel and its field editor starts at x=26, right of the search button (a frameless one, measured for comparison, at x=0); a #FFFFFF14 fill with radius 8 on a borderless search field, measured in pixels on a dark row: 177 against 129 for a twin with none, its corner 96 as the row; a plain borderless field's background on the layer, back on the field when framed. The screenshot showed the bezeled search field editing "Saturn" right of its magnifier with the cancel button at the end, a rounded translucent fill on a plain borderless field, and separator-coloured borders. Pest: 223 passed on NTS and ZTS.

Look and layout settings (2026-11-24, a scratch real-window harness through these classes, 87 checks, all passing on Homebrew PHP 8.4.27 NTS and ZTS): column and row insets and custom spacing, per-side constraint constants, on-screen moves for a margin, hide, show, reorder, spacing and padding live; CENTER with one margin; grid padding; fixed frame and floor; tab page pins; tooltip; border width, colour and the null colour; the nine named cursors, the frame-resize one, MOVE leaving none; the real pointer moved over a view (posted mouse moves) shows the pointing hand and crosshair, and AppKit's arrow off it and once the cursor is null; a text field shows a cursor set on it; button style, tint, attributed title through label, font and colour changes, DEFAULT back to plain, font, alignment, symbol, file over symbol, none, role keys; search field class and delegate, borderless on and off for both fields, background while borderless, read-only, maximum length left alone; the link writes no colour; the cursor area and monitor go with the primitive. A screenshot showed per-side padding, a 24 pt margin between two labels, a borderless search field, a link-style button with a star symbol in the link colour, bordered buttons (one with green text), and a magenta-coloured link drawn in the link colour. Pest: 223 passed on NTS and ZTS.

List, collection and tree (2026-11-22, a scratch harness, NTS and ZTS, and by screenshot): code changes post nothing; select, open, close and activate post; disabled items post nothing; removal. All sixteen primitives of that day's three batches also render from one Sharp file.

Table and text area (2026-11-22, a scratch harness, NTS and ZTS): fill, focus, code changes post nothing, the user's selection and typing post, line selection, scroll to a line, removal.

Scroll view, tab view, split view and grid (2026-11-22, a scratch harness in a real window, untouched, NTS and ZTS): sizes, fill through a plain row, code changes post nothing, tab choice, removal. The divider dragged through AppKit's own tracking loop by posted events: one `SplitMoved` with the last position, and the pane stays there.

Separator, progress bar, spinner, slider, toggle button, segmented control and image (2026-11-22, a scratch harness, 25 checks in a real window on NTS and ZTS): sizes in a column and a row; code changes post nothing; the user's slide, press and choice post their mail; disabled posts nothing; PNG and SVG show, another file is refused; removal. Looked at in a screenshot.

Text input, combo box, date picker and link (2026-11-21, a scratch real-window harness, 30 checks, active and inactive window): code changes post nothing; typing, Return, option, completion and pick post one mail each; completions keep focus and hide on Esc, choice and focus leaving; disabled posts nothing; removal. The link is not clicked.

Check box, switch and dropdown (2026-11-21, a scratch real-window harness, 11 checks): changes from code post nothing; the user's toggle, flip and choice each post one mail with the new value; new options with a selection post nothing; nothing from inside a disabled column; removal. Driven by performClick and, for the popup, selectItemAtIndex plus sendActionTo.

The real-window harness (a scratch topside-dev, 35 checks) through these classes passes: building, natives inside their containers, fixed frames, label text, alignment and font, a native click posting `ButtonClicked`, the enabled cascade, `focus()` moving between two buttons with `hasFocus()`, reordering, transitions, text colour, removal, close. Commit 6eb1d79.

[^native]: AppkitNative
