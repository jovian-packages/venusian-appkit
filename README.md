# jovian/venusian-appkit

AppKit composition for Venusian Surface on macOS. Installing it publishes the
macOS session behind `mac.bridge`, the AppKit stage host behind
`stage.appkit`, and the input engine behind `input.appkit`.

```
ext-appkit  →  jovian/appkit  →  venusian-appkit  →  Surface
1:1 binding    typed projection   composition        cross-platform
```

## Install

```bash
composer require jovian/venusian-appkit
```

Requires macOS, PHP 8.4+ and `ext-appkit` 0.8.0.

## Input

`input.appkit` reads the keyboard and mouse for native windows and appkit
stages from the extension's NSEvent tap, and gamepads from
GameController.framework (`gc-<handle>`). It never pumps: the `os` resource
drains NSApp, and each poll folds what the tap recorded.

- Keys in the engine's windows that no view takes are consumed after
  recording, so AppKit does not beep. Command keys still reach the menu bar.
- Focus loss releases every held key and mouse button (with release edges).
- `mouse()->wheel()`: `dy > 0` = rolled away, whatever the scroll setting.
- A pad with an extended profile is a game controller; a micro profile is a
  game pad. Private profile classes (`GCDualShockGamepad`) work.

```php
use Surface\HumanInput\MagicAliases\HumanInput;

$mac = HumanInput::engine('appkit');       // or INPUT_ENGINE=appkit
$mac->keyboard()->text();
foreach ($mac->gameControllers() as $id => $pad) {   // 'gc-<handle>'
    $pad->leftStick();
}
```

See [`.okf/`](.okf/index.md) for the knowledge bundle.

## License

MIT.
