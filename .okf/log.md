# jovian/venusian-appkit Update Log

## 2026-08-31 (video)
* **Creation**: `AppKitVideo` over `AVPlayerView` (INLINE controls) with an `AVPlayer`
  minted per path from `NSURL::fileURLWithPath`. The AVPlayer box is a held property —
  the view retains natively but play/pause/mute need the handle later. A plain NSView
  like the spinner, so it pays the inversion itself; no natural size, measure() answers
  the current frame. Proven live: rate 1 / timeControlStatus PLAYING on a real mp4.

## 2026-08-31 (spinner, image, wrap)
* **Creation**: `AppKitSpinner` over `NSProgressIndicator` (SPINNING style, hidden when
  stopped, `startAnimation(0)`) — a plain NSView, not NSControl, so it pays the top-left
  inversion itself instead of sharing `TranslatesAppKitFrames`. `AppKitImage` over
  `NSImageView` (PROPORTIONALLY_UP_OR_DOWN; `initWithContentsOfFile`, boxes held in
  locals per the temp-box rule). `AppKitLabel` wrap: single-line-mode off + word-wrap +
  `preferredMaxLayoutWidth`; wrapped height from `cell()->cellSizeForBounds` — worked
  only after jovian/appkit's parenting fix made `NSTextFieldCell extends NSCell`.

## 2026-08-30 (temp-box rule)
* **Root cause pinned** (was "mechanism unpinned" after the menu-bar Heisenbug):
  chaining `->handle` off a temporary box frees the box before the outer call —
  destructor → registry release → ext resolves nil. Proven headless: temp-chained
  `setTextColor` reads back 0, variable-held reads back the handle. All style call
  sites now hold locals; rule added to AGENTS. Symptom shape: handle-taking setters
  silently no-op (or set nil), bool/scalar setters unaffected.

## 2026-08-30 (style)
* **Creation**: `ComposesAppKitStyle` (Color→NSColor, FontSpec→NSFont, FontWeight→
  the NSFontWeight doubles). Labels style directly (`setTextColor`/`setFont`/
  `setDrawsBackground`); buttons recompose an `NSAttributedString` title on every
  label/colour/font change and take backgrounds through their layer via raw CGColor
  bits.

## 2026-08-30 (button)
* **Creation**: `Views\AppKitButton` over `NSButton::buttonWithTitleTargetAction` (nil
  target; the class wires `Bridge::setAction` → `fireClick()`). Frame mechanics —
  the y-inversion, `sizeToFit`, `removeFromSuperview` — extracted into the
  `TranslatesAppKitFrames` trait, shared with `AppKitLabel`.

## 2026-08-30 (about, options)
* **Update**: `presentAbout()` passes the options dict again — ext-appkit now marshals the
  PHP array to NSDictionary. Name/version/copyright carried; Credits still skipped
  (NSAttributedString).

## 2026-08-30 (about)
* **Update**: ABOUT role is PHP-backed (`Bridge::setAction` → `showAbout()`) instead of
  the bare `orderFrontStandardAboutPanel:` selector, so the options dict can override
  the "php" an unbundled process shows. Name/version/copyright passed; Credits skipped
  (wants NSAttributedString — measure before promising).

## 2026-08-30 (label)
* **Creation**: `Views\AppKitLabel` over `NSTextField::labelWithString:`. This class pays
  the coordinate debt: every Surface top-left frame becomes
  `NSRect(x, contentHeight - y - h, w, h)` in `applyFrame`. `sizeToFit` measures
  pre-layout. Delegate gained `contentSize()` (content view frame, authoritative at
  once) and `mintLabel()`.

## 2026-08-30 (menus)
* **Update**: menu building moved off the session into `AppKitWindowDelegate::applyMenuBar()`
  — window elects a Surface menu profile, delegate builds the NSMenu tree (role →
  selector table, hooks via `Bridge::setAction`, sketch closures receive Surface's
  `MenuEvent`) and swaps it in as the process's main menu. Bar held on the delegate for
  the later focus-swap slice. The AppKit-dialect session `setMenuBar(array)` is removed.

## 2026-08-30
* **Update**: [BridgedMacOSSession](/session.md) — recorded `provisionNewWindow()`, the
  fifth verb on Surface's contract. Surface cannot own an `NSWindow` constructor, so
  the session is the factory. Notes the hard-coded style mask, the bottom-left origin,
  and why `setReleasedWhenClosed(false)` on the delegate is mandatory.

## 2026-08-29
* **Update**: [BridgedMacOSSession](/session.md) now implements Surface's `MacOSBridge`
  marker so `AppKitWindowDriver` receives a typed session. Proven on a real Mac: connect
  raises the Dock icon, as designed. Dropped process-state notes that belong to no bundle.
* **Initialization**: Seeded the bundle. The package was emptied of its 0.8 view
  drivers, which were written against an older opinionated `ext-appkit`, and is being
  rebuilt on the strict 1:1 projection.
* **Creation**: [BridgedMacOSSession](/session.md) — the four AppKit hooks behind
  Surface's bridge lifecycle, why `finishLaunching()` is the unrepeatable half and
  activation policy is the cyclable one, and the deliberate absence of teardown.
