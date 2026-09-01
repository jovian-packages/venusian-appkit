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
use Surface\NativeWindows\Views\Button;
use Surface\NativeWindows\Windowable;

/**
 * A Surface button over an NSButton. The click goes through the bridge's
 * shared action target into fireClick(), so the sketch's hook runs inside
 * the pump that delivered the click.
 */
class AppKitButton extends Button
{
    use ComposesAppKitStyle;
    use TranslatesAppKitFrames;

    public function __construct(
        string $name,
        Windowable $window,
        string $label,
        public readonly NSButton $button,
    ) {
        parent::__construct($name, $window, $label);

        Bridge::setAction(
            $button->handle,
            fn (ObjCObject $sender) => $this->fireClick(),
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

    protected function applyTextColor(Color $color): void
    {
        $this->recomposeTitle();
    }

    protected function applyFont(FontSpec $font): void
    {
        $this->recomposeTitle();
    }

    /**
     * A styled button title is an attributed string — AppKit's only path to
     * colour and font on a button. Rebuilt whole on every change; the
     * attributes dict carries live handles the ext resolves back to objects.
     */
    protected function recomposeTitle(): void
    {
        // Boxes are held in locals through the calls that use their handles:
        // a temp is freed the moment ->handle is read and the registry entry
        // dies with it. Never chain ->handle off a temp.
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
     * Buttons have no background setter; the layer behind the bezel is the
     * honest AppKit route. The layer CFRetains the CGColor, and the NSColor
     * lives through this call — the raw bits are valid when read.
     */
    protected function applyBackground(Color $color): void
    {
        $this->button->setWantsLayer(true);
        $layer = $this->button->layer();
        if ($layer instanceof CALayer) {
            // The NSColor must outlive the call: its CGColor is raw pointer
            // bits into the colour object, and the layer only CFRetains once
            // setBackgroundColor executes.
            $ns_color = $this->nsColor($color);
            $layer->setBackgroundColor($ns_color->CGColor());
        }
    }
}
