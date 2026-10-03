<?php

namespace Jovian\Toolkits\Appkit\Primitives;

use Jovian\Toolkits\Appkit\Primitives\Concerns\AppkitPrimitive;
use NSColor;
use NSRect;
use NSSecureTextField;
use NSTextField;
use ObjCDelegate;
use ObjCTarget;
use Surface\Contracts\Windows\Mail\View\TextChanged;
use Surface\Contracts\Windows\Mail\View\TextSubmitted;
use Surface\Contracts\Windows\Primitives\Placement;
use Surface\Contracts\Windows\Styling\FontSpec;
use Surface\Contracts\Windows\ToolkitWindow;
use Surface\NutsAndBolts\Color;
use Surface\Windows\Primitives\TKPrimitiveGroup;
use Surface\Windows\Primitives\TKTextInput;

/**
 * An editable NSTextField, or NSSecureTextField when secret. Its delegate's
 * controlTextDidChange: posts TextChanged; its action (Return) posts TextSubmitted.
 */
class AppkitTextInput extends TKTextInput
{
    use AppkitPrimitive;

    /**
     * Controls hold delegates and targets weakly: the primitive keeps both.
     */
    protected ObjCDelegate $delegate;

    protected ObjCTarget $target;

    protected readonly ?NSColor $built_color;

    protected readonly ?NSColor $built_background;

    protected readonly bool $built_draws_background;

    public function __construct(string $name, ToolkitWindow $window, ?TKPrimitiveGroup $parent, Placement $placement, string $value, ?string $placeholder, bool $secret)
    {
        parent::__construct($name, $window, $parent, $placement, $value, $placeholder, $secret);
        $field = $this->adoptNative($secret ? NSSecureTextField::initWithFrame(new NSRect()) : NSTextField::textFieldWithString($value));
        $field->setStringValue($value);
        $field->setPlaceholderString($placeholder);
        $this->built_color = $field->textColor();
        $this->built_background = $field->backgroundColor();
        $this->built_draws_background = $field->drawsBackground();

        $this->delegate = new ObjCDelegate('NSTextFieldDelegate');
        $this->delegate->on('controlTextDidChange:', fn () => $this->typed());
        $field->setDelegate($this->delegate);

        $this->target = new ObjCTarget(fn () => $this->submitted());
        $field->setTarget($this->target);
        $field->setAction(ObjCTarget::ACTION);
    }

    public function native(): NSTextField
    {
        return $this->native;
    }

    protected function applyValue(string $value): void
    {
        $this->native->setStringValue($value);
    }

    protected function applyPlaceholder(?string $placeholder): void
    {
        $this->native->setPlaceholderString($placeholder);
    }

    protected function applyFont(FontSpec $font): void
    {
        $this->native->setFont(self::nsFont($font));
    }

    protected function applyTextColor(?Color $color): void
    {
        $this->native->setTextColor(is_null($color) ? $this->built_color : self::nsColor($color));
    }

    /**
     * The field paints its own background over any layer colour: the colour goes on the field.
     *
     * @param Color|null $color
     * @return void
     */
    protected function applyBackground(?Color $color): void
    {
        $this->native->setBackgroundColor(is_null($color) ? $this->built_background : self::nsColor($color));
        $this->native->setDrawsBackground(is_null($color) ? $this->built_draws_background : true);
    }

    protected function releaseNative(): void
    {
        $this->native->setDelegate(null);
        $this->native->setTarget(null);
    }

    protected function typed(): void
    {
        $this->nativeValueChanged($this->native->stringValue());
        $this->session()->post(new TextChanged($this->window->name(), $this->path(), $this->uuid, $this->value));
    }

    protected function submitted(): void
    {
        $this->nativeValueChanged($this->native->stringValue());
        $this->session()->post(new TextSubmitted($this->window->name(), $this->path(), $this->uuid, $this->value));
    }
}
