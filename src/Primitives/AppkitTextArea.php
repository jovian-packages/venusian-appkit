<?php

namespace Jovian\Toolkits\Appkit\Primitives;

use Jovian\Toolkits\Appkit\Primitives\Concerns\AppkitPrimitive;
use NSAttributedString;
use NSClipView;
use NSColor;
use NSMutableParagraphStyle;
use NSNotificationCenter;
use NSObject;
use NSOperationQueue;
use NSPoint;
use NSRange;
use NSRect;
use NSScrollView;
use NSSize;
use NSTextBlock;
use NSTextBlockDimension;
use NSTextBlockValueType;
use NSTextView;
use ObjCDelegate;
use Surface\Contracts\Windows\Mail\View\TextChanged;
use Surface\Contracts\Windows\Mail\View\ViewScrolled;
use Surface\Contracts\Windows\Primitives\Placement;
use Surface\Contracts\Windows\Styling\FontSpec;
use Surface\Contracts\Windows\Styling\ScrollPosition;
use Surface\Contracts\Windows\ToolkitWindow;
use Surface\NutsAndBolts\Color;
use Surface\Windows\Primitives\TKPrimitiveGroup;
use Surface\Windows\Primitives\TKTextArea;

/**
 * NSTextView::scrollableTextView(): the native is the scroll view, the text view its document.
 * The text view's delegate posts TextChanged from textDidChange:. Disabled or read-only means
 * not editable; it still selects. Not filling, it stands at the height of its text; its
 * background is the text view's.
 *
 * Line backgrounds are text blocks (one per styled paragraph, 100 % of the container wide),
 * which TextKit 1 draws: the view is switched to it by asking for its layout manager. Range
 * colours are storage attributes. Offsets are UTF-16, through the abstract's TextLines.
 * Scrolls are the clip view's bounds changes, observed synchronously so a code-driven scroll
 * is told apart by the flag it raises.
 */
class AppkitTextArea extends TKTextArea
{
    use AppkitPrimitive;

    /**
     * A storage attribute beside each range foreground, holding the same colour. setTextColor:
     * leaves it alone, and it moves with the user's edits as the foreground does.
     */
    private const string RANGE_FOREGROUND = 'TKTextAreaRangeForeground';

    /** FLT_MAX: the container size Apple's no-wrap setup uses. */
    private const float UNBOUNDED = 3.4028234663852886E+38;

    protected NSTextView $text;

    protected NSClipView $clip;

    /**
     * Text views hold their delegate weakly: the primitive keeps it.
     */
    protected ObjCDelegate $delegate;

    protected readonly ?NSColor $built_color;

    protected ?NSObject $width_observer = null;

    protected ?NSObject $scroll_observer = null;

    protected float $measured_width = -1.0;

    protected readonly NSColor $built_background;

    protected readonly bool $built_draws_background;

    /**
     * True while code moves or rewrites the view: the clip view's bounds changes are ours.
     */
    protected bool $quiet = false;

    public function __construct(string $name, ToolkitWindow $window, ?TKPrimitiveGroup $parent, Placement $placement, string $value)
    {
        parent::__construct($name, $window, $parent, $placement, $value);
        $scroll = $this->adoptNative(NSTextView::scrollableTextView());
        $this->text = $scroll->documentView();
        // TextKit 1 (asking for the layout manager switches to it): text blocks draw only there. Non-contiguous
        // layout keeps a 100,000-line file at ~1 ms to first draw instead of 3-4 s of whole-text layout.
        $this->text->layoutManager()->setAllowsNonContiguousLayout(true);
        $this->text->setString($this->value);
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

        // No queue: the block runs inside the change, so $quiet still says whose it was.
        $this->clip = $scroll->contentView();
        $this->clip->setPostsBoundsChangedNotifications(true);
        $this->scroll_observer = NSNotificationCenter::defaultCenter()->addObserverForNameObjectQueueUsingBlock(
            NSViewBoundsDidChangeNotification,
            $this->clip,
            null,
            fn () => $this->scrolled(),
        );
    }

    public function native(): NSScrollView
    {
        return $this->native;
    }

    /**
     * The storage is replaced with plain text in the view's font and colour: setString: would
     * carry the caret's attributes (a text block, a colour) into the new text. The clip view
     * keeps its bounds inside the new text inside the guard: that scroll is the value's.
     */
    protected function applyValue(string $value): void
    {
        $attributes = [NSForegroundColorAttributeName => $this->foreground()];
        $font = $this->text->font();
        if (! is_null($font)) {
            $attributes[NSFontAttributeName] = $font;
        }
        $this->quietly(function () use ($value, $attributes): void {
            $this->text->textStorage()->setAttributedString(NSAttributedString::initWithStringAttributes($value, $attributes));
            $this->remeasure();
        });
    }

    /**
     * The new font relays the text out to a new size, and the clip view keeps its bounds inside
     * it: that scroll is the font's, so the font, the layout and the remeasure all run quiet.
     */
    protected function applyFont(FontSpec $font): void
    {
        $this->quietly(function () use ($font): void {
            $this->text->setFont(self::nsFont($font));
            $this->remeasure();
        });
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

    /**
     * The view's colour is an attribute over all the text: range foregrounds go back on top,
     * read from their RANGE_FOREGROUND runs, which sit where the user's edits have left them.
     */
    protected function applyTextColor(?Color $color): void
    {
        $this->text->setTextColor(is_null($color) ? $this->built_color : self::nsColor($color));
        $storage = $this->text->textStorage();
        $length = $storage->length();
        $storage->beginEditing();
        for ($at = 0; $at < $length; $at = $run->location + $run->length) {
            [$value, $run] = $storage->attributeAtIndexEffectiveRange(self::RANGE_FOREGROUND, $at);
            if ($value instanceof NSColor) {
                $storage->addAttributeValueRange(NSForegroundColorAttributeName, $value, $run);
            }
        }
        $this->quietly(fn () => $storage->endEditing());
    }

    protected function applyNativeEnabled(bool $on): void
    {
        $this->text->setEditable($on && ! $this->read_only);
    }

    protected function applyReadOnly(bool $read_only): void
    {
        $this->text->setEditable(! $read_only && $this->isEnabled());
    }

    protected function applyWrap(bool $wrap): void
    {
        $container = $this->text->textContainer();
        $this->quietly(function () use ($wrap, $container): void {
            $this->native()->setHasHorizontalScroller(! $wrap);
            $this->text->setHorizontallyResizable(! $wrap);
            $container->setWidthTracksTextView($wrap);
            if ($wrap) {
                $width = $this->clip->bounds()->width;
                $frame = $this->text->frame();
                $this->text->setFrame(new NSRect($frame->x, $frame->y, $width, $frame->height));
                $container->setSize(new NSSize($width, self::UNBOUNDED));
            } else {
                $this->text->setMaxSize(new NSSize(self::UNBOUNDED, self::UNBOUNDED));
                $container->setSize(new NSSize(self::UNBOUNDED, self::UNBOUNDED));
            }
            $this->remeasure();
        });
    }

    protected function applyLineBackgrounds(array $backgrounds): void
    {
        $storage = $this->text->textStorage();
        $lines = $this->lines();
        $storage->beginEditing();
        $storage->removeAttributeRange(NSParagraphStyleAttributeName, new NSRange(0, $storage->length()));
        foreach ($backgrounds as $line => $color) {
            $block = NSTextBlock::init();
            $block->setBackgroundColor(self::nsColor($color));
            $block->setValueTypeForDimension(100.0, NSTextBlockValueType::PERCENTAGE, NSTextBlockDimension::WIDTH);
            $style = NSMutableParagraphStyle::init();
            $style->setTextBlocks([$block]);
            $start = $lines->utf16Start($line);
            $end = $line + 1 < $lines->count() ? $lines->utf16Start($line + 1) : $lines->utf16Total();
            if ($end > $start) {
                $storage->addAttributeValueRange(NSParagraphStyleAttributeName, $style, new NSRange($start, $end - $start));
            }
        }
        $this->quietly(fn () => $storage->endEditing());
    }

    protected function applyRangeStyles(array $styles): void
    {
        $storage = $this->text->textStorage();
        $lines = $this->lines();
        $all = new NSRange(0, $storage->length());
        $storage->beginEditing();
        $storage->removeAttributeRange(NSBackgroundColorAttributeName, $all);
        $storage->removeAttributeRange(self::RANGE_FOREGROUND, $all);
        $storage->addAttributeValueRange(NSForegroundColorAttributeName, $this->foreground(), $all);
        foreach ($styles as $style) {
            $start = $lines->utf16Start($style->line) + $lines->utf16Column($style->line, $style->start);
            $length = $lines->utf16Column($style->line, $style->start + $style->length) - $lines->utf16Column($style->line, $style->start);
            $range = new NSRange($start, $length);
            if (! is_null($style->foreground)) {
                $foreground = self::nsColor($style->foreground);
                $storage->addAttributeValueRange(NSForegroundColorAttributeName, $foreground, $range);
                $storage->addAttributeValueRange(self::RANGE_FOREGROUND, $foreground, $range);
            }
            if (! is_null($style->background)) {
                $storage->addAttributeValueRange(NSBackgroundColorAttributeName, self::nsColor($style->background), $range);
            }
        }
        $this->quietly(fn () => $storage->endEditing());
    }

    protected function applySelectLines(int $first, int $last): void
    {
        $lines = $this->lines();
        $start = $lines->utf16Start($first);
        $end = $lines->utf16Start($last) + $lines->utf16Length($last);
        $this->text->setSelectedRange(new NSRange($start, $end - $start));
    }

    protected function nativeSelectedLines(): array
    {
        $range = $this->text->selectedRange();
        $lines = $this->lines();
        $first = $lines->lineAtUtf16($range->location);

        return [$first, $range->length > 0 ? $lines->lineAtUtf16($range->location + $range->length - 1) : $first];
    }

    protected function nativeScrollPosition(): ScrollPosition
    {
        $visible = $this->clip->bounds();
        $content = $this->text->frame();

        return ScrollPosition::of($visible->x, $visible->width, $content->width, $visible->y, $visible->height, $content->height);
    }

    protected function applyScrollTo(float $x, float $y): void
    {
        $visible = $this->clip->bounds();
        $content = $this->text->frame();
        $this->scrollClipTo(
            ScrollPosition::offset($x, $visible->width, $content->width),
            ScrollPosition::offset($y, $visible->height, $content->height),
        );
    }

    protected function applyScrollToLine(int $line): void
    {
        $lines = $this->lines();
        $visible = $this->clip->bounds();
        $content = $this->text->frame();
        $start = $lines->utf16Start($line);
        if ($start >= $lines->utf16Total()) {
            $top = $content->height;
        } else {
            $manager = $this->text->layoutManager();
            $glyphs = $manager->glyphRangeForCharacterRange(new NSRange($start, 1));
            $top = $manager->boundingRectForGlyphRangeInTextContainer($glyphs, $this->text->textContainer())->y
                + $this->text->textContainerInset()->height;
        }
        $this->scrollClipTo($visible->x, max(0.0, min($top, $content->height - $visible->height)));
    }

    protected function releaseNative(): void
    {
        $this->text->setDelegate(null);
        foreach (['width_observer', 'scroll_observer'] as $observer) {
            if (! is_null($this->{$observer})) {
                NSNotificationCenter::defaultCenter()->removeObserver($this->{$observer});
                $this->{$observer} = null;
            }
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

    protected function scrolled(): void
    {
        if ($this->quiet || $this->removed || ! $this->watching_scroll) {
            return;
        }
        $this->session()->postLatest(
            "view.scrolled.{$this->window->name()}.{$this->path()}",
            new ViewScrolled($this->window->name(), $this->path(), $this->uuid, $this->nativeScrollPosition()),
        );
    }

    /**
     * A target within half a point of where the clip view stands is the position it has: a
     * fraction read back and set again lands there give or take float error, and nothing moves.
     */
    private function scrollClipTo(float $x, float $y): void
    {
        $visible = $this->clip->bounds();
        if (abs($x - $visible->x) < 0.5 && abs($y - $visible->y) < 0.5) {
            return;
        }
        $this->quietly(function () use ($x, $y): void {
            $this->clip->scrollToPoint(new NSPoint($x, $y));
            $this->native()->reflectScrolledClipView($this->clip);
        });
    }

    /**
     * Nests: the width observer's block runs synchronously inside the frame change that posts
     * it, so a guarded change can reach another, and the inner one hands back the flag it found.
     *
     * @param \Closure(): void $change
     */
    private function quietly(\Closure $change): void
    {
        $was = $this->quiet;
        $this->quiet = true;
        try {
            $change();
        } finally {
            $this->quiet = $was;
        }
    }

    private function foreground(): NSColor
    {
        return is_null($this->text_color) ? ($this->built_color ?? NSColor::colorWithRedGreenBlueAlpha(0.0, 0.0, 0.0, 1.0)) : self::nsColor($this->text_color);
    }
}
