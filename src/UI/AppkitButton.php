<?php

namespace Jovian\Toolkits\Appkit\UI;

use NSColor;
use NSImage;
use NSButton;
use NSLayoutConstraint;
use ObjCTarget;
use WeakReference;
use NSAttributedString;
use NSBezelStyle;
use NSCellImagePosition;
use NSMutableParagraphStyle;
use Surface\NutsAndBolts\Color;
use Surface\Toolkits\UI\TKButton;
use Surface\Contracts\Toolkits\UI\Insets;
use Surface\Contracts\Toolkits\UI\ButtonRole;
use Surface\Contracts\Toolkits\UI\ButtonStyle;
use Surface\Contracts\Toolkits\UI\IconPosition;
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
 * Its look is NSButton's own: BORDERLESS and LINK are an unbordered button, HELP the help bezel
 * (AppKit's round "?", which draws no title); a text colour (and
 * LINK's NSColor linkColor) is the title's foreground colour, written as an attributed title
 * with the button's font and alignment, and the content tint, which colours a symbol icon too.
 * With no colour the title is plain again. The icon sits at the leading side of the title, or
 * the trailing side (IMAGE_TRAILING) when setIconPosition() says so;
 * Padding: NSButton has no content insets, and the cell draws its title centred in the frame, so
 * the button is sized instead: width and height constraints equal to its intrinsic size plus the
 * padding, at priority PADDING_PRIORITY (above a stack's 750 hugging, so the button is not
 * pulled back to its natural size; a required constraint from a container, or a tie at 999,
 * still wins). They are made again whenever the label, font, icon, icon position, style, colour
 * or alignment changes the intrinsic size. The room is split evenly: padding that differs left
 * and right (or top and bottom) comes out as half the total on each side. AppKit has no
 * per-side inset, and none is faked. Insets::none() removes the constraints.
 * With padding the cell is also told to hug (imageHugsTitle): title and icon are one group,
 * centred in the room, so the padding sits at the outer edges. Without padding it stays off, as
 * AppKit's own button (a stretched button keeps its icon at the edge).
 *
 * The role is the key equivalent (Return for DEFAULT, which AppKit also draws as the window's
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

    /** Above the 750 hugging of a stack's children, under a tie at 999. */
    private const float PADDING_PRIORITY = 800.0;

    /**
     * The width and height constraints that hold the padding; none while there is none.
     * @var list<NSLayoutConstraint>
     */
    private array $padding_constraints = [];

    /** The bezel the button was made with: back on it when HELP is no longer the style. */
    private ?NSBezelStyle $bezel = null;

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
        $this->bezel = $this->buttonNative()->bezelStyle();
    }

    protected function applyLabel(string $label): void
    {
        $this->retitle();
    }

    protected function applyPadding(Insets $padding): void
    {
        $this->refit();
    }

    protected function applyStyle(ButtonStyle $style): void
    {
        // The help bezel is AppKit's round "?": it draws no title, the label stays its accessibility text.
        $this->buttonNative()->setBordered($style->framed());
        $this->buttonNative()->setBezelStyle($style === ButtonStyle::HELP ? NSBezelStyle::HELP_BUTTON : $this->bezel);
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
        $button->setImagePosition($this->imagePosition(! is_null($image)));
        $this->refit();
    }

    protected function applyIconPosition(IconPosition $position): void
    {
        $button = $this->buttonNative();
        $button->setImagePosition($this->imagePosition(! is_null($button->image())));
        $this->refit();
    }

    /**
     * Where the image goes: none without one, else at the side setIconPosition() chose.
     */
    private function imagePosition(bool $hasImage): NSCellImagePosition
    {
        return match (true) {
            ! $hasImage => NSCellImagePosition::NO_IMAGE,
            $this->iconPosition() === IconPosition::TRAILING => NSCellImagePosition::IMAGE_TRAILING,
            default => NSCellImagePosition::IMAGE_LEADING,
        };
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
        NSLayoutConstraint::deactivateConstraints($this->padding_constraints);
        $this->padding_constraints = [];
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
            $this->refit();

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
        $this->refit();
    }

    /**
     * The padding's constraints for the button as it is now: its intrinsic size (title, icon
     * and bezel as AppKit measures them) plus the padding on both sides of each axis.
     */
    private function refit(): void
    {
        NSLayoutConstraint::deactivateConstraints($this->padding_constraints);
        $this->padding_constraints = [];

        if (is_null($this->native)) {
            return;
        }

        $padding = $this->padding();
        $button = $this->buttonNative();
        $button->setImageHugsTitle($padding != Insets::none());
        if ($padding == Insets::none()) {
            return;
        }

        $size = $button->intrinsicContentSize();
        $this->padding_constraints = [
            $button->widthAnchor()->constraintEqualToConstant($size->width + $padding->left + $padding->right),
            $button->heightAnchor()->constraintEqualToConstant($size->height + $padding->top + $padding->bottom),
        ];
        foreach ($this->padding_constraints as $constraint) {
            $constraint->setPriority(self::PADDING_PRIORITY);
        }
        NSLayoutConstraint::activateConstraints($this->padding_constraints);
    }

    private function buttonNative(): NSButton
    {
        /** @var NSButton */
        return $this->native();
    }
}
