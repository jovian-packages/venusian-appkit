---
type: Runbook
title: Testing
description: Pest suite against the real toolkit in a workbench of path repos; macOS only.
resource: tests/
tags: [appkit, pest]
status: draft
generated: { by: claude-opus/5.5, at: 2026-10-02T22:38:01Z }
sources:
  - id: pest
    resource: tests/Pest.php
    title: tests/Pest.php
---

# Overview

Suite drives the real toolkit: windows appear on screen. One driver per process (one application per process), built in `tests/Pest.php` with a `ControlPanel` container holding `config`, `toolkit-bridge`, `toolkit-windows`; helpers `driver()`, `session()`, `takeMail()` (empties the outbox), `pumpFor($seconds)` and `pumpUntil($condition, $seconds)`, both through the real sleeper (`ToolkitPump::sleep`, which flushes latest mail per pump as the loop does).[^pest]

Covers: session lifecycle, budgeted wait, loop join (kqueue/epoll fd ends the toolkit sleep), open/close/focus mail, menu build, item/toggle/quit mail, About, default bar, profile swap; primitives (`tests/Primitives/`): containers and layout, every leaf's native state and mail, resize mail, table, video (`tests/fixtures/clip.mp4`, 1 s 64×64 H.264).

Every primitive test file closes all windows before and after each test: an open window's events end a budgeted wait early. Wait on conditions (`pumpUntil`) with generous limits, never a fixed pump, for activation and playback: under machine load a fixed 0.3 s missed key status. Presenting a window posts `WindowFocused`; drain it before asserting on later mail.

Workbench (never the repo root):

1. Copy the package (no vendor) to a scratch dir.
2. Path repositories, symlinked: `<framework>/src/Voyager/*`, `<surface>/src/Surface/*`.
3. `composer install`; `php84 vendor/bin/pest` and `zhp vendor/bin/pest`.

Delete the workbench (and Pi copy) after.

[^pest]: tests/Pest.php
