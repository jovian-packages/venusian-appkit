<?php

namespace Jovian\Venusian\AppKit\Views;

use Jovian\Bindings\AppKit\NS\NSScrollView;
use Jovian\Bindings\AppKit\NS\NSTextView;
use Jovian\Bindings\AppKit\Runtime\Delegate;
use Jovian\Bindings\AppKit\Values\NSRect;
use Surface\Contracts\NativeWindows\Views\Color;
use Surface\Contracts\NativeWindows\Views\FontSpec;
use Surface\NativeWindows\Views\TextArea;
use Surface\NativeWindows\Windowable;

/**
 * A Surface text area over NSTextView::scrollableTextView() — AppKit's own
 * pre-wired pair, so wrapping, autoresizing and vertical growth behave the
 * way every Mac editor does. The scroll view is the framed node; the text
 * view is the content.
 *
 * Edits arrive through an NSTextViewDelegate hearing textDidChange: and
 * read the text view's string back — AppKit CAN read its buffer, so the
 * mail always carries the value.
 */
class AppKitTextArea extends TextArea
{
    use ComposesAppKitStyle;

    /** Held for the life of the editor — PHP refcount owns the native delegate. */
    protected Delegate $text_delegate;

    public function __construct(
        string $name,
        Windowable $window,
        string $value,
        public readonly NSScrollView $scroll,
        public readonly NSTextView $text,
    ) {
        parent::__construct($name, $window, $value);

        $this->text_delegate = new Delegate('NSTextViewDelegate');
        $this->text_delegate->on('textDidChange:', function (mixed ...$args): void {
            $this->fireChanged($this->text->string_() ?? '');
        });
        $text->setDelegate($this->text_delegate->handle());
    }

    protected function applyValue(string $value): void
    {
        $this->text->setString($value);
    }

    protected function applyEditable(bool $editable): void
    {
        $this->text->setEditable($editable);
        // Selection stays honest either way — read-only text is still text.
        $this->text->setSelectable(true);
    }

    protected function applyFrame(int $x, int $y, int $width, int $height): void
    {
        [, $content_height] = $this->layoutSpace();

        $this->scroll->setFrame(new NSRect(
            (float) $x,
            (float) ($content_height - $y - $height),
            (float) $width,
            (float) $height,
        ));
    }

    /** A text area has no natural size worth trusting; hug keeps the frame. */
    protected function measure(): array
    {
        return [$this->width, $this->height];
    }

    protected function destroyNative(): void
    {
        $this->scroll->removeFromSuperview();
    }

    protected function applyVisible(bool $visible): void
    {
        $this->scroll->setHidden(! $visible);
    }

    protected function applyTextColor(Color $color): void
    {
        $ns_color = $this->nsColor($color);
        $this->text->setTextColor($ns_color->handle);
    }

    protected function applyFont(FontSpec $font): void
    {
        $ns_font = $this->nsFont($font);
        $this->text->setFont($ns_font->handle);
    }

    protected function applyBackground(Color $color): void
    {
        $ns_color = $this->nsColor($color);
        $this->text->setDrawsBackground(true);
        $this->text->setBackgroundColor($ns_color->handle);
    }
}
