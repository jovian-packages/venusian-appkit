<?php

namespace Jovian\Venusian\AppKit\Views;

use Jovian\Bindings\AppKit\NS\NSAttributedString;
use Jovian\Bindings\AppKit\NS\NSButton;
use Jovian\Bindings\AppKit\NS\NSControl;
use Jovian\Bindings\AppKit\QuartzCore\CALayer;
use Jovian\Bindings\AppKit\Runtime\Bridge;
use Jovian\Bindings\AppKit\Runtime\ObjCObject;
use Surface\Contracts\NativeWindows\Views\Color;
use Surface\Contracts\NativeWindows\Views\FontSpec;
use Surface\NativeWindows\Views\ToggleButton;
use Surface\NativeWindows\Windowable;

/**
 * A Surface toggle button over an NSButton in the push-on/push-off type.
 * The press lands in fireToggled() with the state AppKit holds.
 */
class AppKitToggleButton extends ToggleButton
{
    use ComposesAppKitStyle;
    use TranslatesAppKitFrames;

    public function __construct(
        string $name,
        Windowable $window,
        string $label,
        bool $pressed,
        public readonly NSButton $button,
    ) {
        parent::__construct($name, $window, $label, $pressed);

        Bridge::setAction(
            $button->handle,
            fn (ObjCObject $sender) => $this->fireToggled($this->button->state() === 1),
        );
    }

    protected function control(): NSControl
    {
        return $this->button;
    }

    protected function applyLabel(string $label): void
    {
        if (is_null($this->text_color) && is_null($this->font)) {
            $this->button->setTitle($label);

            return;
        }

        $this->recomposeTitle();
    }

    protected function applyPressed(bool $pressed): void
    {
        $this->button->setState($pressed ? 1 : 0);
    }

    protected function applyEnabled(bool $enabled): void
    {
        $this->button->setEnabled($enabled);
    }

    protected function applyTextColor(Color $color): void
    {
        $this->recomposeTitle();
    }

    protected function applyFont(FontSpec $font): void
    {
        $this->recomposeTitle();
    }

    /**
     * The attributed-title rebuild AppKitButton documents — AppKit's only
     * path to colour and font on a button title.
     */
    protected function recomposeTitle(): void
    {
        // Boxes live in locals until AppKit has retained natively — never
        // chain ->handle off a temp.
        $ns_color = is_null($this->text_color) ? null : $this->nsColor($this->text_color);
        $ns_font = is_null($this->font) ? null : $this->nsFont($this->font);

        $attributes = [];
        if (! is_null($ns_color)) {
            $attributes['NSColor'] = $ns_color->handle;
        }
        if (! is_null($ns_font)) {
            $attributes['NSFont'] = $ns_font->handle;
        }

        $title = NSAttributedString::initWithStringAttributes($this->button_label, $attributes);
        if ($title instanceof NSAttributedString) {
            $this->button->setAttributedTitle($title->handle);
        }
    }

    /**
     * Same honest route as AppKitButton: the layer behind the bezel.
     */
    protected function applyBackground(Color $color): void
    {
        $this->button->setWantsLayer(true);
        $layer = $this->button->layer();
        if ($layer instanceof CALayer) {
            $ns_color = $this->nsColor($color);
            $layer->setBackgroundColor($ns_color->CGColor());
        }
    }
}
