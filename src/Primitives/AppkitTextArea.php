<?php

namespace Jovian\Toolkits\Appkit\Primitives;

use Jovian\Toolkits\Appkit\Primitives\Concerns\AppkitPrimitive;
use NSColor;
use NSNotificationCenter;
use NSObject;
use NSOperationQueue;
use NSScrollView;
use NSTextView;
use ObjCDelegate;
use Surface\Contracts\Windows\Mail\View\TextChanged;
use Surface\Contracts\Windows\Primitives\Placement;
use Surface\Contracts\Windows\Styling\FontSpec;
use Surface\Contracts\Windows\ToolkitWindow;
use Surface\NutsAndBolts\Color;
use Surface\Windows\Primitives\TKPrimitiveGroup;
use Surface\Windows\Primitives\TKTextArea;

/**
 * NSTextView::scrollableTextView(): the native is the scroll view, the text view its document.
 * The text view's delegate posts TextChanged from textDidChange:. Disabled means not editable.
 * Not filling, it stands at the height of its text; its background is the text view's.
 */
class AppkitTextArea extends TKTextArea
{
    use AppkitPrimitive;

    protected NSTextView $text;

    /**
     * Text views hold their delegate weakly: the primitive keeps it.
     */
    protected ObjCDelegate $delegate;

    protected readonly ?NSColor $built_color;

    protected ?NSObject $width_observer = null;

    protected float $measured_width = -1.0;

    protected readonly NSColor $built_background;

    protected readonly bool $built_draws_background;

    public function __construct(string $name, ToolkitWindow $window, ?TKPrimitiveGroup $parent, Placement $placement, string $value)
    {
        parent::__construct($name, $window, $parent, $placement, $value);
        $scroll = $this->adoptNative(NSTextView::scrollableTextView());
        $this->text = $scroll->documentView();
        $this->text->setString($value);
        $this->built_color = $this->text->textColor();
        $this->built_background = $this->text->backgroundColor();
        $this->built_draws_background = $this->text->drawsBackground();

        $this->delegate = new ObjCDelegate('NSTextViewDelegate');
        $this->delegate->on('textDidChange:', fn () => $this->edited());
        $this->text->setDelegate($this->delegate);

        // A new width wraps the text to a new height.
        $this->text->setPostsFrameChangedNotifications(true);
        $this->width_observer = NSNotificationCenter::defaultCenter()->addObserverForNameObjectQueueUsingBlock(
            NSViewFrameDidChangeNotification,
            $this->text,
            NSOperationQueue::mainQueue(),
            fn () => $this->rewrapped(),
        );
    }

    public function native(): NSScrollView
    {
        return $this->native;
    }

    protected function applyValue(string $value): void
    {
        $this->text->setString($value);
        $this->remeasure();
    }

    protected function applyFont(FontSpec $font): void
    {
        $this->text->setFont(self::nsFont($font));
        $this->remeasure();
    }

    protected function applyBackground(?Color $color): void
    {
        $this->text->setBackgroundColor(is_null($color) ? $this->built_background : self::nsColor($color));
        $this->text->setDrawsBackground(is_null($color) ? $this->built_draws_background : true);
    }

    /**
     * The height of the laid-out text at the text view's width, plus its insets: read from the
     * layout manager, which does not follow the viewport the way the text view's frame does.
     *
     * @param bool $horizontal
     * @return float
     */
    protected function naturalLength(bool $horizontal): float
    {
        if ($horizontal) {
            return 0.0;
        }
        $manager = $this->text->layoutManager();
        $container = $this->text->textContainer();
        $manager->ensureLayoutForTextContainer($container);

        return ceil($manager->usedRectForTextContainer($container)->height + 2 * $this->text->textContainerInset()->height);
    }

    protected function applyTextColor(?Color $color): void
    {
        $this->text->setTextColor(is_null($color) ? $this->built_color : self::nsColor($color));
    }

    protected function applyNativeEnabled(bool $on): void
    {
        $this->text->setEditable($on);
    }

    protected function releaseNative(): void
    {
        $this->text->setDelegate(null);
        if (! is_null($this->width_observer)) {
            NSNotificationCenter::defaultCenter()->removeObserver($this->width_observer);
            $this->width_observer = null;
        }
    }

    protected function rewrapped(): void
    {
        $width = $this->text->frame()->width;
        if ($width !== $this->measured_width) {
            $this->measured_width = $width;
            $this->remeasure();
        }
    }

    protected function edited(): void
    {
        $this->nativeValueChanged($this->text->string());
        $this->remeasure();
        $this->session()->post(new TextChanged($this->window->name(), $this->path(), $this->uuid, $this->value));
    }
}
