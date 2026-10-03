---
type: Module
title: Primitives
description: "AppKit concretes of Surface's TK primitives: factory, the shared native trait, stack/grid/fixed/scroll containers, leaves, layout model, mail."
resource: src/Primitives/
tags: [appkit, primitives, autolayout, layout]
status: draft
generated: { by: claude-opus/5.5, at: 2026-10-02T23:09:40Z }
sources:
  - id: trait
    resource: src/Primitives/Concerns/AppkitPrimitive.php
    title: AppkitPrimitive
  - id: stack
    resource: src/Primitives/Concerns/AppkitStack.php
    title: AppkitStack
  - id: factory
    resource: src/Primitives/AppkitPrimitiveFactory.php
    title: AppkitPrimitiveFactory
  - id: grid
    resource: src/Primitives/AppkitGrid.php
    title: AppkitGrid
  - id: fixed
    resource: src/Primitives/AppkitFixed.php
    title: AppkitFixed
  - id: scroll
    resource: src/Primitives/AppkitScrollView.php
    title: AppkitScrollView
  - id: leaves
    resource: src/Primitives/
    title: Appkit leaf concretes
---

# Overview

`AppkitWindow::factory()` (one per window) mints `Appkit<Kind>` for every Surface kind; nothing unavailable on AppKit. Mint = `new Appkit<Kind>(name, window, host, host?->takePlacement() ?? Placement::next(), …)`; ctor calls `parent::__construct()` first, then builds its NSView and hands it to `adoptNative()` (Auto Layout on).[^factory]

Shared trait `AppkitPrimitive` (every concrete):[^trait]

* Three views, usually one: `native()` = the toolkit view; `ownView()` = what it adds to layout (a grid's frame around its NSGridView); `placedView()` = what its container arranges (a slot around `ownView()` while it fills a stack's main axis but is aligned START/CENTER/END there).
* Hidden = placed view `setHidden` (a hidden view stops making ancestors fill); background = layer colour on `ownView()`, except text input / text area / table, which paint their own background (colour goes on the field / text view / table view; null restores the built one); min size = required `>=` constraints on `ownView()` (a fixed's child: widens its frame instead).
* Enabled = own state AND every ancestor's; containers push it down (`syncNativeEnabled`), each child keeps its own `isEnabled()`. Text area = `setEditable`, table = its NSTableView, video = controls style INLINE / NONE.
* `watchSize()` = `NSViewFrameDidChangeNotification` block observer (main queue) → `postLatest("view.resized.<window>.<path>", ViewResized)`, only when the size changed; off / remove → observer gone + `forgetLatest`.
* `size()` = frame, rounded. A label's frame sits 2pt outside its alignment rect.
* Remove: container drops its placement constraints (`removeNative`), concrete's `releaseNative()` (targets, delegates, observers), `removeFromSuperview`.
* Fonts: system font at FontWeight, or family via `NSFontManager` (0–15 weight scale); unknown family → system font, as GTK/Qt fall back. Colour = `NSColor` sRGB.

# Layout model

GTK's: `fill` = expand into spare space on the container's main axis; `align` = place within the space given, on both axes (FILL stretches). Fill propagates up: a container fills when any live visible descendant does (GTK computed expand). Fillers share spare space equally (Qt's stretch 1 each).[^stack]

Priorities, so content never resizes a window by preference:

| Priority | Use |
|---|---|
| 1000 | required: padding bounds, Align anchors, min size, fixed extent |
| 750 | hugging of a non-filling view; controls' compression resistance (minimums do grow a window, as GTK minimum sizes do) |
| 500 | NSWindow size-stay-put |
| 490 | a wrapping label's horizontal compression: a narrow window wraps it, a non-filling container still gives it its line |
| 480 | pull toward natural length on views with no intrinsic size (stacks, grids, fixed, scroll, slots, image, table, text area) |
| 200 | equal-length ties between fillers |
| 1 | hugging of a filling view |

| Container | Native | Rules |
|---|---|---|
| Column / row | `NSStackView` | GRAVITY_AREAS distribution while no child fills (natural sizes, packed from start, leftover empty; FILL distribution with nobody filling collapses the window), FILL while one does (a gravity-area gap hugs at the filling stack's priority 1, the same as the filler's growth, so a nested filler loses the spare space as often as it wins it), alignment NOT_AN_ATTRIBUTE; spacing, padding = edge insets. Main axis: hug unless fills; fillers tied equal; a filler aligned START/CENTER/END sits in a slot that takes the space. Cross: `>= start+pad`, `<= end-pad`, then Align: FILL both edges, START/CENTER/END one anchor. |
| Grid | `NSGridView` in a frame view | Pinned top-left in the frame; on an axis nothing fills, trailing/bottom `<=` the frame and the grid pulled to natural size (no hugging reaches the window; leftover stays empty, as GtkGrid / Qt's spare line); on a filled axis `==` and fillers tied equal. Rows/columns added on demand; span = merged cells; Align → cell x/y placement. Padding = outer padding of first/last row and column. AppKit cannot unmerge: removing a spanning child rebuilds the NSGridView inside the frame from the live children (not while the grid itself is being removed).[^grid] |
| Fixed | plain `NSView` | Children keep frames by autoresizing; frames top-left based, y flipped against the fixed's height, re-framed on its own frame change; a frame is widened to the child's min size and fitting size. Natural size = extent of the frames (`>=` constraints).[^fixed] |
| Scroll view | `NSScrollView` | One container as document, pinned to the clip view's top/leading: top stays in view, stays put as content grows. Scrolling axis: document `>=` viewport; non-scrolling: `==` viewport.[^scroll] |

Hugging only acts on an intrinsic size. A view without one hugs through the 480 pull toward `naturalLength()`: 0, or what it shows read from the toolkit (an image's point size, a table's rows plus header, a text area's laid-out text through its layout manager), re-measured when that changes.[^trait]

# Leaves

| Kind | Native | Mail |
|---|---|---|
| Label | `NSTextField::labelWithString`; wrap = word wrapping, 0 lines, horizontal compression 250; unwrap/null colour restore what AppKit built | — |
| Button | push `NSButton`, text colour = content tint | ButtonClicked (target) |
| Image | layer-backed `NSView`, image as layer contents, clipped; FIT/FILL/CENTER/STRETCH = kCAGravity resizeAspect/resizeAspectFill/center/resize. Natural size = NSImage point size (PNG dpi honoured). Unreadable file → WindowException; refused `setFile()` keeps `file()` | — |
| Separator | `NSBox` SEPARATOR, built long in its direction, 1pt thick | — |
| Spinner | `NSProgressIndicator` SPINNING, hidden when stopped | — |
| Progress bar | `NSProgressIndicator` BAR 0..1; null = indeterminate, animating | — |
| Text input | `NSTextField` / `NSSecureTextField` | TextChanged (`controlTextDidChange:`), TextSubmitted (action on Return) |
| Text area | `NSTextView::scrollableTextView()` (native = scroll view) | TextChanged (`textDidChange:`) |
| Checkbox / toggle / toggle button | checkbox `NSButton` / `NSSwitch` / PUSH_ON_PUSH_OFF `NSButton` | Toggled |
| Slider | continuous `NSSlider` | ValueChanged |
| Dropdown | `NSPopUpButton`, options as menu items (repeats stay separate) | SelectionChanged |
| Datepicker | stepper `NSDatePicker`, year-month-day, in PHP's default zone (a day is midnight of that day in it both ways); null shows today | DateChanged |
| Table | `NSTableView` in a scroll view, data source from the normalised rows | RowSelected — only when the table's selection differs from Surface's (code changes echo, post nothing) |
| Video | `AVPlayerView` (inline controls), one `AVPlayer`, an `AVPlayerItem` per file | VideoPlaying/Paused (timeControlStatus KVO), VideoFailed (item status), VideoEnded (end notification; AVPlayer's own pause at the end is the end). Loop = end action NONE + seek 0 at the end, no mail. `play()` at the end restarts. A new file stops playback silently |

Mail only from native callbacks; code-side setters post nothing. Targets, delegates and observers are kept on the primitive (AppKit holds them weakly).[^leaves]

# Driver-internal API

Public on concretes for cross-object use, not for apps: `ownView`, `placedView`, `placeChild`, `removeNative`, `refitChild` (fixed), `nativeHug`, `nativeSlot`, `nativeFills`, `nativeAlign`, `syncNativeEnabled`. Containers read a child's fill/align/min size through the shared `TKPrimitive` ancestor.

[^trait]: AppkitPrimitive
[^stack]: AppkitStack
[^factory]: AppkitPrimitiveFactory
[^grid]: AppkitGrid
[^fixed]: AppkitFixed
[^scroll]: AppkitScrollView
[^leaves]: Appkit leaf concretes
