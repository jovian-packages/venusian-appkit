---
type: Design
title: Video
description: The design for TKVideo on AppKit - a video manager with drivers inside this package, the 'webview' driver (WKWebView showing the media as its document) as the default, the 'avkit' driver (AVPlayer through jovian/venusian-avkit), how a driver is chosen and verified, the three ext-avkit bindings to add, what was measured, and the build stages.
tags: [appkit, toolkits, video, design]
status: approved
generated: { by: claude-fable/5.2, at: 2026-11-27T05:00:00Z }
sources:
  - id: tkvideo
    resource: ../../venusian/topside/src/Surface/Toolkits/UI/TKVideo.php
    title: TKVideo (the hooks a toolkit fills in)
  - id: manager
    resource: ../../venusian/framework/src/Voyager/NutsAndBolts/Manager.php
    title: Manager (driver(), create{Name}Driver(), extend())
  - id: adapter
    resource: src/AppkitAdapterDriver.php
    title: AppkitAdapterDriver (the primitive map)
  - id: webview
    resource: src/UI/AppkitWebView.php
    title: AppkitWebView (WKWebView and its navigation delegate)
  - id: raven-video
    resource: ../venusian-raven/src/UI/RavenVideo.php
    title: RavenVideo (a video element driven by script, reports by message)
  - id: ext-webkit
    resource: ../../php-io-extensions/appkit/stubs/WebKit.stub.php
    title: ext-appkit WebKit surface (commit 6c877a5)
  - id: webkit-origin
    resource: https://github.com/WebKit/WebKit/blob/main/Source/WebCore/page/SecurityOrigin.cpp
    title: SecurityOrigin::canDisplay (filesHaveSameVolume)
  - id: probes
    resource: probe runs 2026-11-26, PHP 8.4 NTS, ext-appkit 0.11.2, ext-avkit 0.11.2, macOS 15.4.1
    title: WKWebView and AVPlayer media probes
  - id: ext-avkit
    resource: ../../php-io-extensions/avkit/stubs/AVFoundation.stub.php
    title: ext-avkit AVFoundation surface (AVPlayer, AVPlayerItem), and AVKit.stub.php (AVPlayerView)
  - id: avplayer-header
    resource: MacOSX.sdk AVFoundation.framework/Headers/AVPlayer.h, AVPlayerItem.h
    title: seekToTime:toleranceBefore:toleranceAfter:, AVPlayerItemFailedToPlayToEndTimeNotification
  - id: retired-player
    resource: deprecated2/Primitives/Concerns/AppkitPlayer.php
    title: The retired AVPlayer code (same calls, before the manager)
---

# Status: approved

Angel approved this document on 2026-11-27, with `Jovian\Media\Avkit` as the namespace of `jovian/venusian-avkit`. Every stage but the stargazer2 views is built: [UI primitives](/architecture/ui-primitives.md), Video; ext-avkit 5be15ec; jovian/venusian-avkit 145b3c3.

# What the author gets

`<Video>` works on AppKit. A source that is an absolute file path, a `file://` URL or an `https://` URL plays in the view, with the mail and the calls `TKVideo` already defines. The app picks the player with one config value: `webview` (the default) or `avkit`. Nothing changes in topside, in the framework or in ext-appkit. ext-avkit gets three bindings.

# Manager and drivers

The package gets its own video manager. The composition asks for a `TKVideo`; this package's primitive asks the manager for the loaded driver, and the driver makes the player.

| Piece | File | What it is |
|---|---|---|
| `AppkitVideo` | `src/UI/AppkitVideo.php` | The one `TKVideo` of this package, added to the primitive map in `AppkitAdapterDriver`. It owns no player code: every `apply*` and `read*` hook forwards to the player its driver made. |
| `AppkitVideoManager` | `src/Video/AppkitVideoManager.php` | Extends the framework's `Manager`. `getDefaultDriver()` reads `appkit.video.driver`. `createWebviewDriver()` makes the driver below. Other packages and apps add drivers with `extend()`. |
| `AppkitVideoDriver` | `src/Video/AppkitVideoDriver.php` | Interface. `player(AppkitVideoListener $listener): AppkitVideoPlayer` makes one player for one view. A driver verifies what it needs in its constructor and throws with the fix when something is missing. |
| `AppkitVideoPlayer` | `src/Video/AppkitVideoPlayer.php` | Interface, one per view: `view(): NSView`, `load(?string $url, bool $muted, bool $loop, bool $controls)`, `play()`, `pause()`, `seek(float)`, `setMuted(bool)`, `setLoop(bool)`, `setControls(bool)`, `position(): float`, `duration(): ?float`, `release()`. |
| `AppkitVideoListener` | `src/Video/AppkitVideoListener.php` | Interface the player reports to: `playing()`, `paused()`, `ended()`, `failed(?string $reason)`. `AppkitVideo` implements it by calling `TKVideo`'s protected reports. |
| `WebViewVideoDriver`, `WebViewVideoPlayer` | `src/Video/WebView/` | The `webview` driver. Every line that knows about a web view, a page or a script is in these two files. |
| `AvkitVideoDriver`, `AvkitVideoPlayer` | `src/Video/Avkit/` | The `avkit` driver. Every line that knows about `jovian/venusian-avkit` is in these two files. |

How the primitive reaches the manager: `AppkitAdapterDriver` holds the framework core, so it builds the manager once (`video(): AppkitVideoManager`). `AppkitWindow` already holds its `AppkitAdapterDriver`, and `AppkitVideo` asks its window. No global lookup, no topside change.

Config, in `config/appkit.php`:

```php
'video' => [
    'driver' => 'webview',
],
```

The two drivers are the check on the interfaces: `AppkitVideo`, the manager and the three interfaces name no web view and no AVPlayer. A player gives an `NSView` and takes a URL; how it shows the media is its own business.

A driver name the manager does not know throws the manager's own "Driver [x] not supported." A driver that cannot run (its extension or package is missing) throws when it is made, with the install line, the way `AppkitAdapterDriver` does for ext-appkit.

# The webview driver

One `WKWebView` per player. The media is the page: WebKit shows a media file as a document that holds one `<video>` element (audio files too).

| Source | Call |
|---|---|
| `https://`, `http://` | `loadRequest:` with the URL |
| `file://` | `loadFileURL:allowingReadAccessToURL:` with the file as both URLs |
| null | `loadHTMLString:baseURL:` with an empty page |

Why the media is the page, and not an HTML page that holds a `<video>`:

* WebKit lets a page read a local file only through `loadFileURL:allowingReadAccessToURL:`. An HTML string has no read access at all.
* On macOS a `file://` page shows a `file://` resource only when both are on the same volume (`SecurityOrigin::canDisplay`, `filesHaveSameVolume`). Only "universal access" skips the check, and WebKit has no public API for it.
* With the media as the page, both rules hold for every file on every volume, the read access is one file, and nothing is written next to the user's media. The same code path plays `https`.

## Script and reports

* A `WKUserScript` at document start finds the `<video>` element (now, or by a `MutationObserver` when WebKit adds it). It marks the element as taken, because on a media document the script finds the same element twice (measured). It then sets `autoplay` to false, pauses, sets `muted`, `loop` and `controls` from the values `load()` got, adds the page style (black page, the picture whole and filling the view), and listens.
* The script is built again for each `load()`: `removeAllUserScripts`, then `addUserScript:`.
* Reports go through one `WKScriptMessageHandler`. Each report is JSON: event, position, duration, error. `playing` → `playing()`, `pause` → `paused()`, `ended` → `ended()`, `error` → `failed()`. `position()` and `duration()` are the last report (`timeupdate`, `durationchange`, `seeked`, `loadedmetadata`).
* WebKit sends `pause` before `ended` at the end of the media, with the position at the duration. That pause is dropped.
* `play()`, `pause()`, `seek()`, `setMuted()`, `setLoop()` and `setControls()` run with `evaluateJavaScript:completionHandler:`. Until the script reports that it has the element, they wait in a queue.
* Buffering is WebKit's: `play()` on an `https` mp4 starts when WebKit has enough data, and `VideoPlaying` is posted then.

## Failures

| What happens | Result |
|---|---|
| The server cannot be reached; the file is not there | `didFailProvisionalNavigation` → `failed()` with the error's localized description |
| The document ends with no `<video>` element (a 404 page, a file that is not media) | a user script at document end reports it → `failed()` |
| The media cannot be decoded | the element's `error` event → `failed()` with its message |
| `WebKitErrorDomain` 204, "Plug-in handled load" | not a failure: WebKit ends every media document's navigation with it |
| `NSURLErrorCancelled` | not a failure: another `load()` replaced this one |

## Release

`release()` removes the message handler, clears the navigation delegate, stops the load and loads an empty page, in that order, so a removed view posts nothing. The handler and the delegate hold the player weakly.

## Verification when the driver is made

The driver needs `WKWebView`, `WKUserContentController`, `WKUserScript` and `WKScriptMessage`. All are in ext-appkit 0.11.2 at commit 6c877a5. When a class is missing, the driver throws and names the ext-appkit version to install.

# The avkit driver

`appkit.video.driver` set to `avkit`. Not the default. `createAvkitDriver()` in the manager makes it.

## Two packages

| Package | What it holds |
|---|---|
| `jovian/venusian-avkit` (`Jovian\Media\Avkit`) | The player on AVFoundation and AVKit: one `AVPlayer`, an `AVPlayerItem` per source, the observers, and the `AVPlayerView` that shows it. It knows nothing about topside's `TKVideo` or about this package. Today the package is a scaffold with no source and a placeholder manifest, so this work gives it its manifest (requires `ext-avkit`), its player class, its tests, README and bundle. |
| `jovian/venusian-appkit` | `AvkitVideoDriver` and `AvkitVideoPlayer`. The player wraps one venusian-avkit player: `view()` returns its `AVPlayerView` (an `NSView` of ext-appkit's object model, so it goes in the layout like any native), and each call forwards. |

Coupling is package to package, and only a suggestion: `jovian/venusian-avkit` goes in this package's `suggest`. When the driver is made it verifies that the venusian-avkit player class exists and that ext-avkit is loaded. When one is missing it throws and gives the `composer require` or `pie install` line.

## How the player works

| `AppkitVideoPlayer` | AVFoundation |
|---|---|
| `load($url, …)` | pause, drop the old item and its observers, `AVPlayerItem playerItemWithURL:` (a file URL or an https URL), `replaceCurrentItemWithPlayerItem:`. It stays paused at 0. |
| `play()`, `pause()` | `play`, `pause`. `play()` at the end seeks to 0 first. |
| `seek()` | `seekToTime:toleranceBefore:toleranceAfter:` with both tolerances zero (new binding) |
| `setMuted()` | `setMuted:` |
| `setLoop()` | `actionAtItemEnd` NONE, and a seek to 0 at the end notification; PAUSE when off |
| `setControls()` | `AVPlayerView controlsStyle` INLINE or NONE |
| `position()`, `duration()` | `currentTime`; the item's `duration` once its status is ready |
| `playing()`, `paused()` | KVO on the player's `timeControlStatus`. The pause AVPlayer makes at the end is the end, not a pause. |
| `ended()` | `AVPlayerItemDidPlayToEndTimeNotification` |
| `failed()` | KVO on the item's `status` (FAILED, with the item's error), and `AVPlayerItemFailedToPlayToEndTimeNotification` for a source that fails after it started (new binding) |

Buffering is AVPlayer's: after `play()` the status is "waiting to play" until it has enough data, then "playing", and `VideoPlaying` is posted then.

## ext-avkit: three bindings to add

Everything else the driver calls is in ext-avkit 0.11.2 and ext-appkit 0.11.2 today (`ObjCObserver`, `NSNotificationCenter`, `NSNotification userInfo`). Each of the three is a real member of AVFoundation:

| Binding | Why |
|---|---|
| `AVPlayer seekToTime:toleranceBefore:toleranceAfter:` | Measured: `seekToTime:` to 0.9 s on the fixture landed at 0 (the key frame). AVPlayer.h says the same: the time "may differ from the specified time for efficiency. For sample accurate seeking see seekToTime:toleranceBefore:toleranceAfter:". `TKVideo::seek()` is seconds from the start, and the webview driver's seek is exact. |
| `AVPlayerItemFailedToPlayToEndTimeNotification` | The report for a source that fails after it started (the network drops). The item's status reports only a source that never becomes ready. |
| `AVPlayerItemFailedToPlayToEndTimeErrorKey` | The key of that notification's `NSError` in `userInfo`: the reason `VideoFailed` carries. |

# Measured

Probe runs on 2026-11-26. The clip is the 1.2 s fixture `clip.mp4`; the audio is `tone.m4a`. The external volume is an APFS volume on a USB drive.

| Route | Media on | Result |
|---|---|---|
| HTML string, base URL the clip's folder | internal disk | error 4; kernel log: sandbox denied the read |
| File page on the internal disk, read access `/` | external volume | error 4; WebKit log: "rejected by SecurityOrigin" |
| File page on the internal disk, read access `/` | internal disk | played to the end |
| Media as the page, read access its folder | external volume, internal disk | played to the end |
| Media as the page, read access the file alone | external volume | played to the end |
| Media as the page over HTTP, mp4 and m4a | local server with byte ranges | played to the end |

Also measured with the media as the page: the element stays paused at 0 until the script calls `play()`; `pause` then `ended` arrive together at the end; a media document's navigation ends with error 204; a 404 gives a document with no `<video>`; a refused connection gives -1004; a missing file gives -1100; a file that is not media gives the element's error 4.

AVPlayer through ext-avkit, same date and fixtures:

| Case | Result |
|---|---|
| File; the clip and the audio over HTTP | item status ready with the duration; paused at 0 until `play()`; "waiting", then "playing"; `pause()` reports paused at the position |
| `seekToTime:` to 0.9 s | landed at 0 |
| Missing file; a file that is not media; a refused connection; a 404 | item status FAILED, each with its own error text |

# Tests

`tests/Integration/VideoTest.php`, run from the workbench ([Testing](/runbooks/testing.md)), on NTS and ZTS. Fixtures `clip.mp4`, `tone.m4a` and `serve.php` (HTTP with byte ranges), the same three files the Qt and Raven packages use.

* A file plays to the end: `VideoPlaying`, then `VideoEnded`, and `duration()` is the clip's.
* The same for the clip over HTTP, and for the audio file.
* A file on a volume other than the system's plays (the workbench is on the external volume).
* It stays paused until `play()`; `pause()` posts `VideoPaused`; `seek()` moves `position()`.
* Muted, loop and controls reach the element, set before the source and after it.
* A missing file, a refused connection, a 404 and a file that is not media each post `VideoFailed` with a reason.
* A new source while one plays posts nothing for the old one; a removed view posts nothing.
* The cases above run once for each driver, `webview` and `avkit`: one data set, the driver name the only difference.
* A seek to 0.9 s reads back 0.9 s on both drivers.
* The manager: the default is `webview`; `avkit` gives the avkit driver; an unknown name throws; a driver added with `extend()` is the one used; the avkit driver without venusian-avkit throws with the install line.

`jovian/venusian-avkit` has its own suite for its player, and ext-avkit has a case for each new binding.

# Stages

1. The manager, the three interfaces, `AppkitVideo`, the config key, and the map entry.
2. The `webview` driver and its tests.
3. The README section, this bundle's UI primitives page and log, then the view in stargazer2 (step 3 of the schedule).
4. The three ext-avkit bindings, with tests.
5. `jovian/venusian-avkit`: manifest, player, tests, README, bundle.
6. The `avkit` driver here and its tests, then the view in stargazer2 with `appkit.video.driver` set to `avkit` (steps 5 and 6 of the schedule).

# Not in this work

* A mail that says "ready to play" (for a loading face in a view). `TKVideo` has no such mail today. It would be a topside contract with a signal from every toolkit, so it is its own design.
