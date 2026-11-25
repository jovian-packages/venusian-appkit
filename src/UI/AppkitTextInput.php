<?php

namespace Jovian\Toolkits\Appkit\UI;

use NSRect;
use NSColor;
use NSObject;
use NSTextField;
use ObjCDelegate;
use WeakReference;
use NSSearchField;
use NSControlSize;
use NSFocusRingType;
use NSSecureTextField;
use NSLayoutConstraint;
use Surface\NutsAndBolts\Color;
use Surface\Toolkits\UI\TKTextInput;
use Surface\Contracts\Toolkits\Styling\FontSpec;
use Surface\Contracts\Toolkits\Styling\TextAlignment;
use Jovian\Toolkits\Appkit\UI\Concerns\AppkitText;
use Jovian\Toolkits\Appkit\UI\Concerns\AppkitNative;
use Jovian\Toolkits\Appkit\UI\Concerns\TakesInputFromAbove;

/**
 * An editable NSTextField, NSSecureTextField when secret, NSSearchField when a search field.
 * Return is the field editor's insertNewline: command; the field's action is not used, since
 * AppKit also sends it when editing ends (a Tab out, a click elsewhere).
 *
 * Borderless is the field's own switches: no bezel, no border, no background drawn and no
 * focus ring; false puts back what it was built with. A borderless search field keeps its
 * bezel, which is its rounded fill, and loses only its focus ring: without the bezel
 * NSSearchField still draws the placeholder past its search button, but the field editor it
 * edits in covers the whole width and the text runs under the button, and the text sits at
 * the top of a taller field (measured 2026-11-24; the cell answers the right rects and does
 * not use them). The bright line round the bezel is macOS's Increase Contrast setting, not
 * the field: every stock NSSearchField has it while that is on.
 *
 * A search field at least as tall as AppKit's large control (minSize) is the large control:
 * NSControlSize LARGE, 30 points against 22, with rounder corners. The bezel fills a taller
 * frame and keeps the text centred.
 *
 * Background: a field that draws its own (a plain field with its frame) is given the colour
 * as its own backgroundColor. One that draws none, a borderless field or any search field
 * (NSSearchField draws no backgroundColor, observed), gets it on its layer, which the corner
 * radius rounds.
 *
 * Read-only is not editable (still selectable). A maximum length is not shown: an NSTextField
 * has no limit of its own (it takes one only through a formatter, and ext-appkit binds no
 * NSFormatter).
 */
class AppkitTextInput extends TKTextInput
{
    use AppkitNative {
        releaseNative as private releaseView;
        applyBackground as private layerBackground;
        applyMinSize as private floorSize;
    }
    use AppkitText;
    use TakesInputFromAbove;

    /** An editable field has no width of its own. */
    private const float NATURAL_WIDTH = 120.0;

    /** Controls hold their delegates weakly: the primitive keeps it. */
    private ?ObjCDelegate $delegate = null;

    private ?NSLayoutConstraint $natural_width = null;

    private ?NSColor $built_color;

    private ?NSColor $built_background;

    private bool $built_draws;

    private bool $built_bezeled;

    private bool $built_bordered;

    private NSFocusRingType $built_focus_ring;

    /** What setBackground() chose; null is the field's own. */
    private ?Color $background = null;

    protected function bootstrapNative(): void
    {
        $field = match (true) {
            $this->isSecret() => NSSecureTextField::initWithFrame(new NSRect(0.0, 0.0, 0.0, 0.0)),
            $this->isSearch() => NSSearchField::initWithFrame(new NSRect(0.0, 0.0, 0.0, 0.0)),
            default => NSTextField::textFieldWithString($this->value()),
        };
        $field->setStringValue($this->value());
        $field->setPlaceholderString($this->placeholder());
        $this->built_color = $field->textColor();
        $this->built_background = $field->backgroundColor();
        $this->built_draws = $field->drawsBackground();
        $this->built_bezeled = $field->isBezeled();
        $this->built_bordered = $field->isBordered();
        $this->built_focus_ring = $field->focusRingType();
        $this->adoptNative($field);

        $this->natural_width = $field->widthAnchor()->constraintGreaterThanOrEqualToConstant(self::NATURAL_WIDTH);
        NSLayoutConstraint::activateConstraints([$this->natural_width]);

        $self = WeakReference::create($this);
        $this->delegate = new ObjCDelegate('NSTextFieldDelegate');
        $this->delegate->on('controlTextDidChange:', static function () use ($self): void {
            $input = $self->get();
            $input?->typed($input->fieldNative()->stringValue());
        });
        $this->delegate->on('control:textView:doCommandBySelector:', static function (NSObject $control, NSObject $view, string $selector) use ($self): bool {
            $input = $self->get();
            if (! is_null($input) && $selector === 'insertNewline:') {
                $input->submitted($input->fieldNative()->stringValue());
            }

            return false;    // AppKit still does what the key does
        });
        $field->setDelegate($this->delegate);
    }

    protected function applyValue(string $value): void
    {
        $this->fieldNative()->setStringValue($value);
    }

    protected function applyPlaceholder(?string $placeholder): void
    {
        $this->fieldNative()->setPlaceholderString($placeholder);
    }

    protected function applyFont(FontSpec $font): void
    {
        $this->fieldNative()->setFont(self::nsFont($font));
    }

    protected function applyTextColor(?Color $color): void
    {
        $this->fieldNative()->setTextColor(is_null($color) ? $this->built_color : self::nsColor($color));
    }

    protected function applyAlignment(TextAlignment $alignment): void
    {
        $this->fieldNative()->setAlignment(self::nsAlignment($alignment));
    }

    /**
     * On the field itself while it draws its own background, else on the layer. Null puts back
     * the field's own (none for a borderless field) and clears the layer's.
     */
    protected function applyBackground(?Color $color): void
    {
        $this->background = $color;
        $field = $this->fieldNative();
        $own = $this->drawsOwnBackground();

        $field->setBackgroundColor(! $own || is_null($color) ? $this->built_background : self::nsColor($color));
        $field->setDrawsBackground(match (true) {
            ! $own => $this->isSearch() ? $this->built_draws : false,
            ! is_null($color) => true,
            default => $this->built_draws,
        });
        if (! $own || $this->layerPainted()) {
            $this->layerBackground($own ? null : $color);
        }
    }

    /**
     * The background moves between the field and its layer with the frame.
     */
    protected function applyBorderless(bool $borderless): void
    {
        $field = $this->fieldNative();

        // A search field keeps its bezel (see the class): only the focus ring goes.
        if ($this->isSearch()) {
            $field->setFocusRingType($borderless ? NSFocusRingType::NONE : $this->built_focus_ring);

            return;
        }

        // A field is bezeled or bordered, not both: turning either on or off sets the other off
        // (observed), so only the one it was built with is turned back on.
        if ($borderless || ! ($this->built_bezeled || $this->built_bordered)) {
            $field->setBordered(false);
            $field->setBezeled(false);
        } elseif ($this->built_bezeled) {
            $field->setBezeled(true);
        } else {
            $field->setBordered(true);
        }
        $field->setFocusRingType($borderless ? NSFocusRingType::NONE : $this->built_focus_ring);
        $this->applyBackground($this->background);
    }

    /**
     * The floor as every primitive keeps it; a search field that tall is AppKit's large one.
     */
    protected function applyMinSize(int $width, int $height): void
    {
        $this->floorSize($width, $height);

        if ($this->isSearch()) {
            $this->fieldNative()->setControlSize($height >= self::largeSearchHeight() ? NSControlSize::LARGE : NSControlSize::REGULAR);
        }
    }

    protected function applyReadOnly(bool $readOnly): void
    {
        $this->fieldNative()->setEditable(! $readOnly);
    }

    protected function applyNativeEnabled(bool $takes_input): void
    {
        $this->fieldNative()->setEnabled($takes_input);
    }

    protected function releaseNative(): void
    {
        $this->fieldNative()->setDelegate(null);
        $this->delegate = null;
        NSLayoutConstraint::deactivateConstraints([$this->natural_width]);
        $this->natural_width = null;
        $this->releaseView();
    }

    /**
     * The height AppKit gives a large search field, measured once on one.
     */
    private static function largeSearchHeight(): float
    {
        static $height = null;
        if (is_null($height)) {
            $field = NSSearchField::initWithFrame(new NSRect(0.0, 0.0, 0.0, 0.0));
            $field->setControlSize(NSControlSize::LARGE);
            $height = $field->intrinsicContentSize()->height;
        }

        return $height;
    }

    /**
     * A plain field with its frame draws its background itself; a borderless or search field
     * draws none.
     */
    private function drawsOwnBackground(): bool
    {
        return ! $this->isSearch() && ! $this->isBorderless();
    }

    /**
     * Whether the layer holds a background, to clear when the field takes it back.
     */
    private function layerPainted(): bool
    {
        return ! is_null($this->native->layer()?->backgroundColor());
    }

    private function fieldNative(): NSTextField
    {
        /** @var NSTextField */
        return $this->native();
    }
}
