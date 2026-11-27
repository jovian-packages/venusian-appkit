<?php

namespace Jovian\Toolkits\Appkit\UI;

use NSRange;
use NSColor;
use NSTextField;
use NSFontManager;
use NSLineBreakMode;
use NSMutableAttributedString;
use NSLayoutConstraintOrientation;
use Surface\NutsAndBolts\Color;
use Surface\Toolkits\UI\TKLabel;
use Surface\Contracts\Toolkits\Styling\FontSpec;
use Surface\Contracts\Toolkits\Styling\TextRun;
use Surface\Contracts\Toolkits\Styling\TextAlignment;
use Jovian\Toolkits\Appkit\UI\Concerns\AppkitText;
use Jovian\Toolkits\Appkit\UI\Concerns\AppkitNative;
use Jovian\Toolkits\Appkit\UI\Concerns\TakesInputFromAbove;

/**
 * NSTextField::labelWithString: not editable, not selectable, takes no focus.
 *
 * Wrapping breaks the text into lines at the width the label is given: its horizontal
 * compression resistance drops to 490, below a window's size-stay-put (500), so a narrow
 * window wraps it instead of widening. Truncating is one line cut at the tail
 * (TRUNCATING_TAIL, one line), with the same low resistance, so a long text is cut at the
 * width the label is given instead of widening what holds it; it wins over wrapping.
 * Neither, and a null colour, restore what AppKit built the label with.
 *
 * Runs are the field's attributed string: the whole text in the label's font and colour, then
 * each run's range (in UTF-16 units, as NSString counts) given the label's font with the bold
 * or italic trait (NSFontManager) and its own colour. No paragraph style is written, so the
 * field's own alignment and line breaking (wrapping) still apply. The field's font, colour and
 * alignment setters rewrite the whole attributed string, so while there are runs every such
 * change sets the field and then writes the runs again; new plain text (setText) is the
 * field's plain string again.
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

    /** @var list<TextRun> the runs shown; empty while the text is plain */
    private array $runs = [];

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
        $this->runs = [];
        $this->labelNative()->setStringValue($text);
    }

    protected function applyRuns(array $runs): void
    {
        $this->runs = $runs;
        $this->writeRuns();
    }

    protected function applyWrap(bool $wrap): void
    {
        $this->applyLines();
    }

    protected function applyTruncate(bool $truncate): void
    {
        $this->applyLines();
    }

    /**
     * Truncating, wrapping, or as built. Preferred max layout width 0 is AppKit's automatic
     * width: the label wraps, or is cut, at the width layout gives it.
     */
    private function applyLines(): void
    {
        [$break, $lines, $resistance] = match (true) {
            $this->truncates() => [NSLineBreakMode::TRUNCATING_TAIL, 1, 490.0],
            $this->wraps() => [NSLineBreakMode::WORD_WRAPPING, 0, 490.0],
            default => [$this->built_break, $this->built_lines, $this->built_resistance],
        };

        $label = $this->labelNative();
        $label->setLineBreakMode($break);
        $label->setMaximumNumberOfLines($lines);
        $label->setContentCompressionResistancePriorityForOrientation($resistance, NSLayoutConstraintOrientation::HORIZONTAL);
        $label->setPreferredMaxLayoutWidth(0.0);
    }

    protected function applyAlignment(TextAlignment $alignment): void
    {
        $this->labelNative()->setAlignment(self::nsAlignment($alignment));
        $this->writeRuns();
    }

    protected function applyFont(FontSpec $font): void
    {
        $this->labelNative()->setFont(self::nsFont($font));
        $this->writeRuns();
    }

    protected function applyTextColor(?Color $color): void
    {
        $this->labelNative()->setTextColor(is_null($color) ? $this->built_color : self::nsColor($color));
        $this->writeRuns();
    }

    protected function applyNativeEnabled(bool $takes_input): void
    {
        $this->labelNative()->setEnabled($takes_input);
    }

    /**
     * The runs as the field's attributed string, over the field's own font and colour. Nothing
     * while the text is plain.
     */
    private function writeRuns(): void
    {
        if ($this->runs === []) {
            return;
        }

        $label = $this->labelNative();
        $font = $label->font();
        $color = $label->textColor();
        $base = [];
        if (! is_null($font)) {
            $base[NSFontAttributeName] = $font;
        }
        if (! is_null($color)) {
            $base[NSForegroundColorAttributeName] = $color;
        }

        $styled = NSMutableAttributedString::initWithStringAttributes(TextRun::join($this->runs), $base);
        $at = 0;
        foreach ($this->runs as $run) {
            $length = self::utf16Length($run->text);
            $range = new NSRange($at, $length);
            $at += $length;
            if ($length === 0) {
                continue;
            }

            if (! is_null($font) && ($run->bold || $run->italic)) {
                $manager = NSFontManager::sharedFontManager();
                $face = $font;
                if ($run->bold) {
                    $face = $manager->convertFontToHaveTrait($face, NSBoldFontMask);
                }
                if ($run->italic) {
                    $face = $manager->convertFontToHaveTrait($face, NSItalicFontMask);
                }
                $styled->addAttributeValueRange(NSFontAttributeName, $face, $range);
            }
            if (! is_null($run->color)) {
                $styled->addAttributeValueRange(NSForegroundColorAttributeName, self::nsColor($run->color), $range);
            }
        }

        $label->setAttributedStringValue($styled);
    }

    /**
     * NSString's length: UTF-16 code units (an emoji outside the Basic Multilingual Plane is 2).
     */
    private static function utf16Length(string $text): int
    {
        return intdiv(strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')), 2);
    }

    private function labelNative(): NSTextField
    {
        /** @var NSTextField */
        return $this->native();
    }
}
