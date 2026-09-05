<?php

namespace Jovian\Venusian\AppKit\Views;

use Jovian\Bindings\AppKit\NS\NSControl;
use Jovian\Bindings\AppKit\NS\NSTextField;
use Jovian\Bindings\AppKit\Runtime\Bridge;
use Jovian\Bindings\AppKit\Runtime\Delegate;
use Jovian\Bindings\AppKit\Runtime\ObjCObject;
use Surface\Contracts\NativeWindows\Views\Color;
use Surface\Contracts\NativeWindows\Views\FontSpec;
use Surface\NativeWindows\Views\TextInput;
use Surface\NativeWindows\Windowable;

/**
 * A Surface text input over an NSTextField (or NSSecureTextField for a
 * secret one — same API, AppKit masks the glyphs).
 *
 * Edits arrive through an NSTextFieldDelegate hearing
 * controlTextDidChange:; Enter arrives through the field's action via the
 * bridge's shared target. Both read the field's own stringValue, so what
 * fireChanged() carries is what AppKit holds.
 */
class AppKitTextInput extends TextInput
{
    use ComposesAppKitStyle;
    use TranslatesAppKitFrames;

    /** Held for the life of the field — PHP refcount owns the native delegate. */
    protected Delegate $field_delegate;

    public function __construct(
        string $name,
        Windowable $window,
        string $value,
        ?string $placeholder,
        bool $secret,
        public readonly NSTextField $field,
    ) {
        parent::__construct($name, $window, $value, $placeholder, $secret);

        $this->field_delegate = new Delegate('NSTextFieldDelegate');
        $this->field_delegate->on('controlTextDidChange:', function (mixed ...$args): void {
            $this->fireChanged($this->field->stringValue() ?? '');
        });
        $field->setDelegate($this->field_delegate->handle());

        Bridge::setAction(
            $field->handle,
            fn (ObjCObject $sender) => $this->fireSubmitted(),
        );
    }

    protected function control(): NSControl
    {
        return $this->field;
    }

    protected function applyValue(string $value): void
    {
        $this->field->setStringValue($value);
    }

    protected function applyPlaceholder(string $placeholder): void
    {
        $this->field->setPlaceholderString($placeholder);
    }

    protected function applyEnabled(bool $enabled): void
    {
        $this->field->setEnabled($enabled);
    }

    protected function applyTextColor(Color $color): void
    {
        $ns_color = $this->nsColor($color);
        $this->field->setTextColor($ns_color->handle);
    }

    protected function applyFont(FontSpec $font): void
    {
        $ns_font = $this->nsFont($font);
        $this->field->setFont($ns_font->handle);
    }

    protected function applyBackground(Color $color): void
    {
        $ns_color = $this->nsColor($color);
        $this->field->setDrawsBackground(true);
        $this->field->setBackgroundColor($ns_color->handle);
    }
}
