<?php

namespace Jovian\Toolkits\Appkit\Primitives;

use Jovian\Toolkits\Appkit\Primitives\Concerns\AppkitPrimitive;
use NSAttributedString;
use NSFont;
use NSMutableParagraphStyle;
use NSTextAlignment;
use NSTextField;
use NSURL;
use Surface\Contracts\Windows\Primitives\Placement;
use Surface\Contracts\Windows\Styling\FontSpec;
use Surface\Contracts\Windows\Styling\TextAlignment;
use Surface\Contracts\Windows\ToolkitWindow;
use Surface\Windows\Primitives\TKLink;
use Surface\Windows\Primitives\TKPrimitiveGroup;

/**
 * A link over a label whose attributed text carries NSLinkAttributeName: AppKit draws it as a link
 * and opens it through NSWorkspace when it is clicked, once the label is selectable and allows
 * editing text attributes. Text, URL, font and alignment changes rebuild the attributed text, so
 * the link, the font and the alignment stay on it together (the alignment as a paragraph style:
 * attributed text draws by its own, not the field's); the URL goes on as an NSURL, or as the string when NSURL
 * cannot parse it (AppKit takes either). With no font set the text carries the font a label is
 * built with, so a link matches the labels beside it and keeps its font while the field editor
 * shows it.
 */
class AppkitLink extends TKLink
{
    use AppkitPrimitive;

    protected readonly NSFont $built_font;

    public function __construct(string $name, ToolkitWindow $window, ?TKPrimitiveGroup $parent, Placement $placement, string $text, string $url)
    {
        parent::__construct($name, $window, $parent, $placement, $text, $url);
        $this->built_font = NSTextField::labelWithString('')->font();
        $label = $this->adoptNative(NSTextField::labelWithAttributedString($this->attributed()));
        $label->setSelectable(true);
        $label->setAllowsEditingTextAttributes(true);
    }

    public function native(): NSTextField
    {
        return $this->native;
    }

    protected function applyText(string $text): void
    {
        $this->relink();
    }

    protected function applyUrl(string $url): void
    {
        $this->relink();
    }

    protected function applyFont(FontSpec $font): void
    {
        $this->native->setFont(self::nsFont($font));
        $this->relink();
    }

    protected function applyAlignment(TextAlignment $alignment): void
    {
        $this->relink();
    }

    /**
     * The attributed text again, then the field's own alignment to match it: attributed text
     * draws by its paragraph style, and the field editor takes the field's.
     */
    protected function relink(): void
    {
        $this->native->setAttributedStringValue($this->attributed());
        $this->native->setAlignment(self::nsAlignment($this->alignment));
    }

    protected function attributed(): NSAttributedString
    {
        $paragraph = NSMutableParagraphStyle::init();
        $paragraph->setAlignment(self::nsAlignment($this->alignment));
        $attributes = [
            NSLinkAttributeName => NSURL::URLWithString($this->url) ?? $this->url,
            NSFontAttributeName => is_null($this->font) ? $this->built_font : self::nsFont($this->font),
            NSParagraphStyleAttributeName => $paragraph,
        ];

        return NSAttributedString::initWithStringAttributes($this->text, $attributes);
    }

    protected static function nsAlignment(TextAlignment $alignment): NSTextAlignment
    {
        return match ($alignment) {
            TextAlignment::LEFT => NSTextAlignment::LEFT,
            TextAlignment::CENTER => NSTextAlignment::CENTER,
            TextAlignment::RIGHT => NSTextAlignment::RIGHT,
        };
    }
}
