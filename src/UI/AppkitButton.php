<?php

namespace Jovian\Toolkits\Appkit\UI;

use NSColor;
use NSImage;
use NSButton;
use ObjCTarget;
use WeakReference;
use NSAttributedString;
use NSCellImagePosition;
use NSMutableParagraphStyle;
use Surface\NutsAndBolts\Color;
use Surface\Toolkits\UI\TKButton;
use Surface\Contracts\Toolkits\UI\ButtonRole;
use Surface\Contracts\Toolkits\UI\ButtonStyle;
use Surface\Contracts\Toolkits\Styling\FontSpec;
use Surface\Contracts\Toolkits\Styling\TextAlignment;
use Jovian\Toolkits\Appkit\UI\Concerns\AppkitText;
use Jovian\Toolkits\Appkit\UI\Concerns\AppkitNative;
use Jovian\Toolkits\Appkit\UI\Concerns\TakesInputFromAbove;

/**
 * A push button whose action posts ButtonClicked. A disabled button sends no action.
 *
 * Keyboard focus follows macOS: focus() makes it first responder, and Tab reaches it only
 * while the user has Full Keyboard Access on.
 *
 * Its look is NSButton's own: BORDERLESS and LINK are an unbordered button; a text colour (and
 * LINK's NSColor linkColor) is the title's foreground colour, written as an attributed title
 * with the button's font and alignment, and the content tint, which colours a symbol icon too.
 * With no colour the title is plain again. The icon sits at the leading side of the title;
 * the role is the key equivalent (Return for DEFAULT, which AppKit also draws as the window's
 * default button, Esc for CANCEL).
 */
class AppkitButton extends TKButton
{
    use AppkitNative {
        releaseNative as private releaseView;
    }
    use AppkitText;
    use TakesInputFromAbove;

    /**
     * The action's target. Controls hold their targets weakly: the primitive keeps it.
     */
    private ?ObjCTarget $target = null;

    /** What setTextColor() chose; null is AppKit's own (the link colour in LINK style). */
    private ?Color $text_color = null;

    protected function bootstrapNative(): void
    {
        // The target holds its callable where PHP's collector cannot see it: a weak reference
        // keeps it from holding the primitive.
        $self = WeakReference::create($this);
        $this->target = new ObjCTarget(static function () use ($self): void {
            $self->get()?->clicked();
        });
        $this->adoptNative(NSButton::buttonWithTitleTargetAction($this->label(), $this->target, ObjCTarget::ACTION));
    }

    protected function applyLabel(string $label): void
    {
        $this->retitle();
    }

    protected function applyStyle(ButtonStyle $style): void
    {
        $this->buttonNative()->setBordered($style === ButtonStyle::DEFAULT);
        $this->retitle();
    }

    protected function applyFont(FontSpec $font): void
    {
        $this->buttonNative()->setFont(self::nsFont($font));
        $this->retitle();
    }

    protected function applyTextColor(?Color $color): void
    {
        $this->text_color = $color;
        $this->retitle();
    }

    protected function applyAlignment(TextAlignment $alignment): void
    {
        $this->buttonNative()->setAlignment(self::nsAlignment($alignment));
        $this->retitle();
    }

    /**
     * The file when AppKit reads it as an image, else the SF Symbol, else none.
     */
    protected function applyIcon(?string $file, ?string $symbol): void
    {
        $image = is_null($file) ? null : NSImage::initWithContentsOfFile($file);
        if (is_null($image) && ! is_null($symbol)) {
            $image = NSImage::imageWithSystemSymbolNameAccessibilityDescription($symbol, $this->label() === '' ? null : $this->label());
        }

        $button = $this->buttonNative();
        $button->setImage($image);
        $button->setImagePosition(is_null($image) ? NSCellImagePosition::NO_IMAGE : NSCellImagePosition::IMAGE_LEADING);
    }

    protected function applyRole(?ButtonRole $role): void
    {
        $this->buttonNative()->setKeyEquivalent(match ($role) {
            ButtonRole::DEFAULT => "\r",
            ButtonRole::CANCEL => "\x1b",
            null => '',
        });
    }

    protected function applyNativeEnabled(bool $takes_input): void
    {
        $this->buttonNative()->setEnabled($takes_input);
    }

    protected function releaseNative(): void
    {
        $this->buttonNative()->setTarget(null);
        $this->target = null;
        $this->releaseView();
    }

    /**
     * The title as it should draw now. setTitle() would drop an attributed title's colour, so
     * every change of label, style, font, colour and alignment writes it here: plain with no
     * colour, else attributed with the button's own font and alignment.
     */
    private function retitle(): void
    {
        $button = $this->buttonNative();
        $color = match (true) {
            ! is_null($this->text_color) => self::nsColor($this->text_color),
            $this->style() === ButtonStyle::LINK => NSColor::linkColor(),
            default => null,
        };
        $button->setContentTintColor($color);

        if (is_null($color)) {
            $button->setTitle($this->label());

            return;
        }

        $paragraph = NSMutableParagraphStyle::init();
        $paragraph->setAlignment($button->alignment());
        $attributes = [
            NSForegroundColorAttributeName => $color,
            NSParagraphStyleAttributeName => $paragraph,
        ];
        $font = $button->font();
        if (! is_null($font)) {
            $attributes[NSFontAttributeName] = $font;
        }
        $button->setAttributedTitle(NSAttributedString::initWithStringAttributes($this->label(), $attributes));
    }

    private function buttonNative(): NSButton
    {
        /** @var NSButton */
        return $this->native();
    }
}
