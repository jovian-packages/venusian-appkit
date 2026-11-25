<?php

namespace Jovian\Toolkits\Appkit\UI;

use NSURL;
use NSFont;
use NSTextField;
use NSAttributedString;
use NSMutableParagraphStyle;
use Surface\Toolkits\UI\TKLink;
use Surface\Contracts\Toolkits\Styling\FontSpec;
use Surface\Contracts\Toolkits\Styling\TextAlignment;
use Jovian\Toolkits\Appkit\UI\Concerns\AppkitText;
use Jovian\Toolkits\Appkit\UI\Concerns\AppkitNative;
use Jovian\Toolkits\Appkit\UI\Concerns\TakesInputFromAbove;

/**
 * A label whose attributed text carries NSLinkAttributeName: selectable and allowing editing
 * of text attributes, AppKit draws it as a link and opens it on a click. Every change writes
 * the attributed text again, so link, font and alignment stay on it together.
 *
 * A text colour is not shown: AppKit draws a link in its own link colour, over the text's
 * NSForegroundColorAttributeName (seen 2026-11-24: a magenta foreground drew in the link
 * colour), and the field editor's linkTextAttributes are not bound.
 */
class AppkitLink extends TKLink
{
    use AppkitNative;
    use AppkitText;
    use TakesInputFromAbove;

    /** The font a label is built with, until one is set. */
    private ?NSFont $font = null;

    protected function bootstrapNative(): void
    {
        $label = NSTextField::labelWithString($this->text());
        $this->font = $label->font();
        $label->setAllowsEditingTextAttributes(true);
        $label->setSelectable(true);
        $this->adoptNative($label);
        $this->relink();
    }

    protected function applyText(string $text): void
    {
        $this->relink();
    }

    protected function applyUrl(string $url): void
    {
        $this->relink();
    }

    protected function applyAlignment(TextAlignment $alignment): void
    {
        $this->relink();
    }

    protected function applyFont(FontSpec $font): void
    {
        $this->font = self::nsFont($font);
        $this->linkNative()->setFont($this->font);
        $this->relink();
    }

    /**
     * Only a selectable field follows its link.
     */
    protected function applyNativeEnabled(bool $takes_input): void
    {
        $this->linkNative()->setSelectable($takes_input);
        $this->linkNative()->setEnabled($takes_input);
    }

    /**
     * Attributed text draws by its paragraph style, and the field editor by the field's own
     * alignment: both are set.
     */
    private function relink(): void
    {
        $alignment = self::nsAlignment($this->alignment);
        $paragraph = NSMutableParagraphStyle::init();
        $paragraph->setAlignment($alignment);

        $this->linkNative()->setAttributedStringValue(NSAttributedString::initWithStringAttributes($this->text(), [
            NSLinkAttributeName => NSURL::URLWithString($this->url()) ?? $this->url(),
            NSFontAttributeName => $this->font,
            NSParagraphStyleAttributeName => $paragraph,
        ]));
        $this->linkNative()->setAlignment($alignment);
    }

    private function linkNative(): NSTextField
    {
        /** @var NSTextField */
        return $this->native();
    }
}
