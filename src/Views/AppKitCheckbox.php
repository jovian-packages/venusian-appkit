<?php

namespace Jovian\Venusian\AppKit\Views;

use Jovian\Bindings\AppKit\NS\NSAttributedString;
use Jovian\Bindings\AppKit\NS\NSButton;
use Jovian\Bindings\AppKit\NS\NSControl;
use Jovian\Bindings\AppKit\Runtime\Bridge;
use Jovian\Bindings\AppKit\Runtime\ObjCObject;
use Surface\Contracts\NativeWindows\Views\Color;
use Surface\Contracts\NativeWindows\Views\FontSpec;
use Surface\NativeWindows\Views\Checkbox;
use Surface\NativeWindows\Windowable;

/**
 * A Surface checkbox over an NSButton minted in the checkbox type. The
 * tick lands in fireToggled() with the state AppKit holds.
 */
class AppKitCheckbox extends Checkbox
{
    use ComposesAppKitStyle;
    use TranslatesAppKitFrames;

    public function __construct(
        string $name,
        Windowable $window,
        string $label,
        bool $checked,
        public readonly NSButton $button,
    ) {
        parent::__construct($name, $window, $label, $checked);

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

    protected function applyChecked(bool $checked): void
    {
        $this->button->setState($checked ? 1 : 0);
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

    /** The attributed-title rebuild AppKitButton documents. */
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

        $title = NSAttributedString::initWithStringAttributes($this->box_label, $attributes);
        if ($title instanceof NSAttributedString) {
            $this->button->setAttributedTitle($title->handle);
        }
    }

    /**
     * A checkbox draws no fill of its own — the colour is ignored, the
     * tick glyph is AppKit's.
     */
    protected function applyBackground(Color $color): void {}
}
