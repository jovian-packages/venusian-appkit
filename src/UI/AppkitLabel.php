<?php

namespace Jovian\Toolkits\Appkit\UI;

use NSColor;
use NSTextField;
use NSLineBreakMode;
use NSLayoutConstraintOrientation;
use Surface\NutsAndBolts\Color;
use Surface\Toolkits\UI\TKLabel;
use Surface\Contracts\Toolkits\Styling\FontSpec;
use Surface\Contracts\Toolkits\Styling\TextAlignment;
use Jovian\Toolkits\Appkit\UI\Concerns\AppkitText;
use Jovian\Toolkits\Appkit\UI\Concerns\AppkitNative;
use Jovian\Toolkits\Appkit\UI\Concerns\TakesInputFromAbove;

/**
 * NSTextField::labelWithString: not editable, not selectable, takes no focus.
 *
 * Wrapping breaks the text into lines at the width the label is given: its horizontal
 * compression resistance drops to 490, below a window's size-stay-put (500), so a narrow
 * window wraps it instead of widening. Unwrapping and a null colour restore what AppKit built
 * the label with.
 */
class AppkitLabel extends TKLabel
{
    use AppkitNative;
    use AppkitText;
    use TakesInputFromAbove;

    private NSLineBreakMode $built_break;

    private int $built_lines;

    private ?NSColor $built_color;

    private float $built_resistance;

    protected function bootstrapNative(): void
    {
        $label = NSTextField::labelWithString($this->text());
        $this->built_break = $label->lineBreakMode();
        $this->built_lines = $label->maximumNumberOfLines();
        $this->built_color = $label->textColor();
        $this->built_resistance = $label->contentCompressionResistancePriorityForOrientation(NSLayoutConstraintOrientation::HORIZONTAL);
        $this->adoptNative($label);

        // NSTextField is built NATURAL; topside's alignment (left until set) is applied now.
        $this->applyAlignment($this->alignment);
    }

    protected function applyText(string $text): void
    {
        $this->labelNative()->setStringValue($text);
    }

    /**
     * Preferred max layout width 0 is AppKit's automatic width: the label wraps at the width
     * layout gives it.
     */
    protected function applyWrap(bool $wrap): void
    {
        $label = $this->labelNative();
        $label->setLineBreakMode($wrap ? NSLineBreakMode::WORD_WRAPPING : $this->built_break);
        $label->setMaximumNumberOfLines($wrap ? 0 : $this->built_lines);
        $label->setContentCompressionResistancePriorityForOrientation($wrap ? 490.0 : $this->built_resistance, NSLayoutConstraintOrientation::HORIZONTAL);
        $label->setPreferredMaxLayoutWidth(0.0);
    }

    protected function applyAlignment(TextAlignment $alignment): void
    {
        $this->labelNative()->setAlignment(self::nsAlignment($alignment));
    }

    protected function applyFont(FontSpec $font): void
    {
        $this->labelNative()->setFont(self::nsFont($font));
    }

    protected function applyTextColor(?Color $color): void
    {
        $this->labelNative()->setTextColor(is_null($color) ? $this->built_color : self::nsColor($color));
    }

    protected function applyNativeEnabled(bool $takes_input): void
    {
        $this->labelNative()->setEnabled($takes_input);
    }

    private function labelNative(): NSTextField
    {
        /** @var NSTextField */
        return $this->native();
    }
}
