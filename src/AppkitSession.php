<?php

namespace Jovian\Toolkits\Appkit;

use NSAppearance;
use NSAttributedString;
use Surface\Toolkits\ToolkitSession;
use Surface\Contracts\Toolkits\Styling\ColorScheme;
use Surface\Toolkits\Menus\AboutPanel;

class AppkitSession extends ToolkitSession
{
    protected function bootstrapToolkitLibrary(): void
    {
        $this->application = new Appkit()
            ->setDelegate()
            ->finishLaunching();
    }

    /**
     * The application's own appearance: dark or light Aqua; none, which follows the system, for SYSTEM.
     */
    protected function applyColorScheme(ColorScheme $scheme): void
    {
        $this->application()->get()->setAppearance(match ($scheme) {
            ColorScheme::DARK => NSAppearance::appearanceNamed(NSAppearanceNameDarkAqua),
            ColorScheme::LIGHT => NSAppearance::appearanceNamed(NSAppearanceNameAqua),
            ColorScheme::SYSTEM => null,
        });
    }

    /**
     * AppKit's standard About panel with the application's fields.
     */
    public function showAbout(AboutPanel $panel): void
    {
        $this->application()->get()->orderFrontStandardAboutPanelWithOptions(self::aboutOptions($panel));
    }

    /**
     * An empty string where a field is not given: without one AppKit shows the PHP binary's own.
     * 'Version' is the build, which it shows in brackets after the version.
     *
     * @internal
     * @return array<string, string|NSAttributedString>
     */
    public static function aboutOptions(AboutPanel $panel): array
    {
        $options = [
            'ApplicationName' => $panel->name,
            'ApplicationVersion' => $panel->version ?? '',
            'Version' => '',
            'Copyright' => $panel->copyright ?? '',
        ];
        if (! is_null($panel->credits)) {
            // The panel takes credits as an attributed string only.
            $options['Credits'] = NSAttributedString::initWithStringAttributes($panel->credits, []);
        }

        return $options;
    }
}