<?php

namespace Jovian\Venusian\AppKit\Views;

use Jovian\Bindings\AppKit\Enums\NSLineBreakMode;
use Jovian\Bindings\AppKit\Enums\NSTextAlignment;
use Jovian\Bindings\AppKit\NS\NSCell;
use Jovian\Bindings\AppKit\NS\NSControl;
use Jovian\Bindings\AppKit\NS\NSTextField;
use Jovian\Bindings\AppKit\Values\NSRect;
use Surface\Contracts\NativeWindows\WindowableException;
use Surface\Contracts\NativeWindows\Views\Color;
use Surface\Contracts\NativeWindows\Views\FontSpec;
use Surface\Contracts\NativeWindows\Views\TextAlignment;
use Surface\NativeWindows\Views\Label;
use Surface\NativeWindows\Windowable;

/**
 * A Surface label over an NSTextField minted with labelWithString:.
 *
 * AppKit's origin is bottom-left; Surface's promise is top-left. This is
 * the class that pays for that: every frame is inverted against the
 * content height before it reaches the view.
 */
class AppKitLabel extends Label
{
    use ComposesAppKitStyle;
    use TranslatesAppKitFrames;

    public function __construct(
        string $name,
        Windowable $window,
        string $text,
        public readonly NSTextField $field,
    ) {
        parent::__construct($name, $window, $text);
    }



    protected function control(): NSControl
    {
        return $this->field;
    }

    protected function applyTextColor(Color $color): void
    {
        // Hold the box in a local: a temp is freed the moment ->handle is
        // read, its destructor releases the registry entry, and the ext
        // resolves nil. Never chain ->handle off a temp.
        $ns_color = $this->nsColor($color);
        $this->field->setTextColor($ns_color->handle);
    }

    protected function applyFont(FontSpec $font): void
    {
        $ns_font = $this->nsFont($font);
        $this->field->setFont($ns_font->handle);
    }

    /**
     * NSTextField draws its own background — no layer needed for labels.
     */
    protected function applyBackground(Color $color): void
    {
        $ns_color = $this->nsColor($color);
        $this->field->setDrawsBackground(true);
        $this->field->setBackgroundColor($ns_color->handle);
    }

    protected function applyText(string $text): void
    {
        $this->field->setStringValue($text);
    }

    protected function applyAlignment(TextAlignment $alignment): void
    {
        $this->field->setAlignment(match ($alignment) {
            TextAlignment::LEFT => NSTextAlignment::LEFT,
            TextAlignment::CENTER => NSTextAlignment::CENTER,
            TextAlignment::RIGHT => NSTextAlignment::RIGHT,
        });
    }

    protected function applyWrap(int $width): void
    {
        $this->field->setUsesSingleLineMode(false);
        $this->field->setLineBreakMode(NSLineBreakMode::NS_LINE_BREAK_BY_WORD_WRAPPING);
        $this->field->setPreferredMaxLayoutWidth((float) $width);
    }

    /**
     * The cell flows the text: cellSizeForBounds at the wrap width with an
     * unbounded height answers what the flow actually needs.
     */
    protected function measureWrappedHeight(int $width): int
    {
        $cell = $this->field->cell();
        if (! $cell instanceof NSCell) {
            throw new WindowableException("AppKit did not hand back a cell to measure '{$this->name}'.");
        }

        $size = $cell->cellSizeForBounds(new NSRect(0.0, 0.0, (float) $width, 100000.0));

        return (int) ceil($size->height);
    }
}
