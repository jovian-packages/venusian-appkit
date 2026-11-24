<?php

namespace Jovian\Toolkits\Appkit\UI;

use NSRect;
use NSColor;
use NSObject;
use NSTextField;
use ObjCDelegate;
use WeakReference;
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
 * An editable NSTextField, or NSSecureTextField when secret. Return is the field editor's
 * insertNewline: command; the field's action is not used, since AppKit also sends it when
 * editing ends (a Tab out, a click elsewhere).
 */
class AppkitTextInput extends TKTextInput
{
    use AppkitNative {
        releaseNative as private releaseView;
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

    protected function bootstrapNative(): void
    {
        $field = $this->isSecret()
            ? NSSecureTextField::initWithFrame(new NSRect(0.0, 0.0, 0.0, 0.0))
            : NSTextField::textFieldWithString($this->value());
        $field->setStringValue($this->value());
        $field->setPlaceholderString($this->placeholder());
        $this->built_color = $field->textColor();
        $this->built_background = $field->backgroundColor();
        $this->built_draws = $field->drawsBackground();
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
     * A field paints its own background over its layer's.
     */
    protected function applyBackground(?Color $color): void
    {
        $this->fieldNative()->setBackgroundColor(is_null($color) ? $this->built_background : self::nsColor($color));
        $this->fieldNative()->setDrawsBackground(is_null($color) ? $this->built_draws : true);
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

    private function fieldNative(): NSTextField
    {
        /** @var NSTextField */
        return $this->native();
    }
}
