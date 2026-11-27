# Venusian AppKit

[![Tests](https://github.com/jovian-packages/venusian-appkit/actions/workflows/tests.yml/badge.svg)](https://github.com/jovian-packages/venusian-appkit/actions/workflows/tests.yml)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/jovian/venusian-appkit.svg)](https://packagist.org/packages/jovian/venusian-appkit)
[![Total Downloads](https://img.shields.io/packagist/dt/jovian/venusian-appkit.svg)](https://packagist.org/packages/jovian/venusian-appkit)
[![License](https://img.shields.io/packagist/l/jovian/venusian-appkit.svg)](LICENSE)

## About Venusian AppKit

> **Note:** This repository contains the AppKit toolkit driver for the Venusian framework. If you want to build an application using Venusian, visit the main [Venusian skeleton repository](https://github.com/VenusianPHP/venusian).

Venusian AppKit is the macOS toolkit driver for Venusian Topside: it connects PHP to `NSApplication`, opens its windows, builds their menu bars and turns Topside's views into real AppKit controls through [ext-appkit](https://github.com/php-io-extensions/appkit), all in the Laravel-like syntax you already know.

Want a Mac app that looks and acts like a Mac app? Write your view once with Topside and Venusian AppKit gives you the real thing: native buttons, text fields, tables, outlines, split and tab views, sheets, alerts, open and save panels, the clipboard, and a menu bar whose Edit items, accelerators and enabled states behave the way macOS users expect.

Need video? A video view plays a file or an https URL through the player you pick in config: a WKWebView by default, with more players added as drivers.

Need more than widgets? A canvas lends its view to Venusian's rendering engines, so Metal, OpenGL, SDL_GPU and Vulkan draw straight into your window with no pixel passing through PHP. Staged windows give a game or a visualizer a whole window of its own, with full screen and exclusive display modes. And with HumanInput installed, the keyboard, the pointer, the wheel and your game controllers read from the same app.

Got a Mac? That's all it takes. Venusian AppKit is macOS only; on Linux, reach for `jovian/venusian-qt`, `jovian/venusian-gtk` or `jovian/venusian-raven` and keep the same views.

> **Built with AI assistance.** This package was developed LLM-assisted — not vibe-coded. Generated code was deliberately prompted and reviewed by a professional software engineer. See [AI-assisted development](#ai-assisted-development).

## Learning Venusian AppKit

Venusian AppKit has documentation and guides on the [Venusian website](https://venusian.projectsaturnstudios.com/docs/0.11.x/ecosystem/framework-surface-modules/venusian-appkit/overview), starting with the [overview](https://venusian.projectsaturnstudios.com/docs/0.11.x/ecosystem/framework-surface-modules/venusian-appkit/overview): the [session](https://venusian.projectsaturnstudios.com/docs/0.11.x/ecosystem/framework-surface-modules/venusian-appkit/guide/session), [windows and menus](https://venusian.projectsaturnstudios.com/docs/0.11.x/ecosystem/framework-surface-modules/venusian-appkit/guide/windows-and-menus), [staged windows](https://venusian.projectsaturnstudios.com/docs/0.11.x/ecosystem/framework-surface-modules/venusian-appkit/guide/staged-windows), [primitives](https://venusian.projectsaturnstudios.com/docs/0.11.x/ecosystem/framework-surface-modules/venusian-appkit/guide/primitives) and [input](https://venusian.projectsaturnstudios.com/docs/0.11.x/ecosystem/framework-surface-modules/venusian-appkit/guide/input).

## AI-assisted development

This codebase was built with LLM assistance under human direction. Prompts, reviews, and architectural decisions were owned by a professional software engineer — the model accelerates drafting and exploration; it does not replace judgment on APIs, dependency direction, or what ships.

## Contributing

Thank you for considering contributing to Venusian AppKit! The contribution guide can be found in the [Venusian documentation](https://venusian.projectsaturnstudios.com/docs/contributions).

## Code of Conduct

In order to ensure that the Venusian community is welcoming to all, please review and abide by the [Code of Conduct](https://venusian.projectsaturnstudios.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

Please review [our security policy](https://github.com/jovian-packages/venusian-appkit/security/policy) on how to report security vulnerabilities.

## License

Venusian AppKit is open-sourced software licensed under the [MIT license](LICENSE).
