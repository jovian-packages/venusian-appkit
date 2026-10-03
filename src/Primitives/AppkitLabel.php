<?php

namespace Jovian\Toolkits\Appkit\Primitives;

use Jovian\Toolkits\Appkit\Primitives\Concerns\AppkitPrimitive;
use NSColor;
use NSLayoutConstraintOrientation;
use NSLineBreakMode;
use NSTextAlignment;
use NSTextField;
use Surface\Contracts\Windows\Primitives\Placement;
use Surface\Contracts\Windows\Styling\FontSpec;
use Surface\Contracts\Windows\Styling\TextAlignment;
use Surface\Contracts\Windows\ToolkitWindow;
use Surface\NutsAndBolts\Color;
use Surface\Windows\Primitives\TKLabel;
use Surface\Windows\Primitives\TKPrimitiveGroup;

/**
 * NSTextField::labelWithString. Wrapping lets the label break into lines at the width it is
 * given: its horizontal compression resistance drops to 490, below the window's size-stay-put
 * (500) so a narrow window wraps it instead of widening, above a container's 480 natural-size
 * pull so a container that does not fill still gives it its full line when there is room.
 * Unwrapping and a null colour restore what AppKit built the label with.
 */
class AppkitLabel extends TKLabel
{
    use AppkitPrimitive;

    protected readonly NSLineBreakMode $built_break;

    protected readonly int $built_lines;

    protected readonly ?NSColor $built_color;

    protected readonly float $built_resistance;

    public function __construct(string $name, ToolkitWindow $window, ?TKPrimitiveGroup $parent, Placement $placement, string $text)
    {
        parent::__construct($name, $window, $parent, $placement, $text);
        $label = $this->adoptNative(NSTextField::labelWithString($text));
        $this->built_break = $label->lineBreakMode();
        $this->built_lines = $label->maximumNumberOfLines();
        $this->built_color = $label->textColor();
        $this->built_resistance = $label->contentCompressionResistancePriorityForOrientation(NSLayoutConstraintOrientation::HORIZONTAL);
    }

    public function native(): NSTextField
    {
        return $this->native;
    }

    protected function applyText(string $text): void
    {
        $this->native->setStringValue($text);
    }

    protected function applyWrap(bool $wrap): void
    {
        $label = $this->native;
        $label->setLineBreakMode($wrap ? NSLineBreakMode::WORD_WRAPPING : $this->built_break);
        $label->setMaximumNumberOfLines($wrap ? 0 : $this->built_lines);
        $label->setContentCompressionResistancePriorityForOrientation($wrap ? 490.0 : $this->built_resistance, NSLayoutConstraintOrientation::HORIZONTAL);
        // 0 = AppKit's automatic width: the label wraps at the width layout gives it.
        $label->setPreferredMaxLayoutWidth(0.0);
    }

    protected function applyAlignment(TextAlignment $alignment): void
    {
        $this->native->setAlignment(match ($alignment) {
            TextAlignment::LEFT => NSTextAlignment::LEFT,
            TextAlignment::CENTER => NSTextAlignment::CENTER,
            TextAlignment::RIGHT => NSTextAlignment::RIGHT,
        });
    }

    protected function applyFont(FontSpec $font): void
    {
        $this->native->setFont(self::nsFont($font));
    }

    protected function applyTextColor(?Color $color): void
    {
        $this->native->setTextColor(is_null($color) ? $this->built_color : self::nsColor($color));
    }
}
