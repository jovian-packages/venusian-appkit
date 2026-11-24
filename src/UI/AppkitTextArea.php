<?php

namespace Jovian\Toolkits\Appkit\UI;

use NSFont;
use NSRect;
use NSSize;
use NSView;
use NSColor;
use NSPoint;
use NSRange;
use NSNumber;
use NSObject;
use NSString;
use NSClipView;
use NSTextView;
use NSTextBlock;
use NSScrollView;
use ObjCDelegate;
use NSFontManager;
use WeakReference;
use NSUnderlineStyle;
use NSAttributedString;
use NSNotificationCenter;
use NSTextBlockDimension;
use NSTextBlockValueType;
use NSMutableParagraphStyle;
use Surface\NutsAndBolts\Color;
use Surface\Toolkits\UI\TKTextArea;
use Surface\Contracts\Toolkits\Styling\FontSpec;
use Jovian\Toolkits\Appkit\UI\Concerns\AppkitText;
use Jovian\Toolkits\Appkit\UI\Concerns\AppkitNative;
use Jovian\Toolkits\Appkit\UI\Concerns\ScrollsInside;
use Surface\Contracts\Toolkits\Styling\TextAlignment;
use Surface\Contracts\Toolkits\Styling\ScrollPosition;
use Jovian\Toolkits\Appkit\UI\Concerns\TakesInputFromAbove;

/**
 * An NSTextView in its scroll view, on TextKit 1 (text blocks draw only there). What code
 * changes or scrolls is inside write().
 */
class AppkitTextArea extends TKTextArea
{
    use AppkitText;
    use ScrollsInside, AppkitNative {
        ScrollsInside::hasFocus insteadof AppkitNative;
        ScrollsInside::nativeFocus insteadof AppkitNative;
        releaseNative as private releaseView;
    }
    use TakesInputFromAbove;

    /**
     * A storage attribute beside each range foreground, holding the same colour. setTextColor:
     * leaves it alone, and it moves with the user's edits as the foreground does.
     */
    private const string RANGE_FOREGROUND = 'TKTextAreaRangeForeground';

    /** FLT_MAX: the container size Apple's no-wrap setup uses. */
    private const float UNBOUNDED = 3.4028234663852886E+38;

    private ?NSTextView $text = null;

    private ?NSClipView $clip = null;

    /**
     * Text views hold their delegate weakly: the primitive keeps it.
     */
    private ?ObjCDelegate $delegate = null;

    private ?NSColor $built_color = null;

    private ?NSFont $built_font = null;

    protected ?NSObject $scroll_observer = null;

    private ?NSColor $built_background = null;

    private bool $built_draws_background = true;

    protected function bootstrapNative(): void
    {
        $scroll = NSTextView::scrollableTextView();
        $this->adoptNative($scroll);
        self::leastSize($scroll);
        $this->text = $scroll->documentView();
        // TextKit 1 (asking for the layout manager switches to it): text blocks draw only there. Non-contiguous
        // layout keeps a 100,000-line file at ~1 ms to first draw instead of 3-4 s of whole-text layout.
        $this->text->layoutManager()->setAllowsNonContiguousLayout(true);
        $this->text->setString($this->value);
        $this->built_color = $this->text->textColor();
        $this->built_font = $this->text->font() ?? NSFont::systemFontOfSizeWeight(13.0, NSFont::WEIGHT_REGULAR);
        $this->built_background = $this->text->backgroundColor();
        $this->built_draws_background = $this->text->drawsBackground();
        // Text with no paragraph style lays out with AppKit's default stops (every 28 pt), not the area's tab width.
        $this->write(fn () => $this->applyLineBackgrounds([]));

        $self = WeakReference::create($this);
        $this->delegate = new ObjCDelegate('NSTextViewDelegate');
        $this->delegate->on('textDidChange:', static function () use ($self): void {
            $area = $self->get();
            if (! is_null($area?->text)) {
                $area->typed($area->text->string());
            }
        });
        $this->text->setDelegate($this->delegate);

        // No queue: the block runs inside the change, so write() still says whose it was.
        $this->clip = $scroll->contentView();
        $this->clip->setPostsBoundsChangedNotifications(true);
        $this->scroll_observer = NSNotificationCenter::defaultCenter()->addObserverForNameObjectQueueUsingBlock(
            NSViewBoundsDidChangeNotification,
            $this->clip,
            null,
            static function () use ($self): void {
                $self->get()?->scrolled();
            },
        );
    }

    protected function focusTarget(): NSView
    {
        return $this->text;
    }

    /**
     * The storage is replaced with plain text in the view's font and colour: setString: would
     * carry the caret's attributes (a text block, a colour) into the new text. The clip view
     * keeps its bounds inside the new text inside the guard: that scroll is the value's.
     */
    protected function applyValue(string $value): void
    {
        $attributes = [
            NSForegroundColorAttributeName => $this->foreground(),
            NSFontAttributeName => $this->areaFont(),
            NSParagraphStyleAttributeName => $this->paragraphStyle(),
        ];
        $this->write(function () use ($value, $attributes): void {
            $this->text->textStorage()->setAttributedString(NSAttributedString::initWithStringAttributes($value, $attributes));
        });
    }

    /**
     * The new font relays the text out to a new size, and the clip view keeps its bounds inside
     * it: that scroll is the font's, so the font and the layout run inside write().
     */
    protected function applyFont(FontSpec $font): void
    {
        $this->write(function () use ($font): void {
            $this->text->setFont(self::nsFont($font));
            $this->applyLineBackgrounds($this->line_backgrounds);
            $this->applyRangeStyles($this->range_styles);
        });
    }

    /** Tab stops follow the font's space advance: every paragraph style is rebuilt. */
    protected function applyTabWidth(int $columns): void
    {
        $this->write(function (): void {
            $this->applyLineBackgrounds($this->line_backgrounds);
        });
    }

    protected function applyAlignment(TextAlignment $alignment): void
    {
        $this->applyLineBackgrounds($this->line_backgrounds);
    }

    protected function applyBackground(?Color $color): void
    {
        $this->text->setBackgroundColor(is_null($color) ? $this->built_background : self::nsColor($color));
        $this->text->setDrawsBackground(is_null($color) ? $this->built_draws_background : true);
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
        $this->write(fn () => $storage->endEditing());
    }

    protected function applyNativeEnabled(bool $takes_input): void
    {
        $this->text->setEditable($takes_input && ! $this->read_only);
    }

    protected function applyReadOnly(bool $read_only): void
    {
        $this->text->setEditable(! $read_only && $this->takesInput());
    }

    protected function applyWrap(bool $wrap): void
    {
        $container = $this->text->textContainer();
        $this->write(function () use ($wrap, $container): void {
            $this->scrollNative()->setHasHorizontalScroller(! $wrap);
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
        });
    }

    protected function applyLineBackgrounds(array $backgrounds): void
    {
        $storage = $this->text->textStorage();
        $lines = $this->lines();
        $storage->beginEditing();
        $storage->addAttributeValueRange(NSParagraphStyleAttributeName, $this->paragraphStyle(), new NSRange(0, $storage->length()));
        $this->text->setDefaultParagraphStyle($this->paragraphStyle());
        foreach ($backgrounds as $line => $color) {
            $block = NSTextBlock::init();
            $block->setBackgroundColor(self::nsColor($color));
            $block->setValueTypeForDimension(100.0, NSTextBlockValueType::PERCENTAGE_VALUE_TYPE, NSTextBlockDimension::WIDTH);
            $style = $this->paragraphStyle();
            $style->setTextBlocks([$block]);
            $start = $lines->utf16Start($line);
            $end = $line + 1 < $lines->count() ? $lines->utf16Start($line + 1) : $lines->utf16Total();
            if ($end > $start) {
                $storage->addAttributeValueRange(NSParagraphStyleAttributeName, $style, new NSRange($start, $end - $start));
            }
        }
        $this->write(fn () => $storage->endEditing());
    }

    protected function applyRangeStyles(array $styles): void
    {
        $storage = $this->text->textStorage();
        $lines = $this->lines();
        $all = new NSRange(0, $storage->length());
        $storage->beginEditing();
        $storage->removeAttributeRange(NSBackgroundColorAttributeName, $all);
        $storage->removeAttributeRange(self::RANGE_FOREGROUND, $all);
        $storage->removeAttributeRange(NSUnderlineStyleAttributeName, $all);
        $storage->removeAttributeRange(NSStrikethroughStyleAttributeName, $all);
        $storage->addAttributeValueRange(NSForegroundColorAttributeName, $this->foreground(), $all);
        $font = $this->areaFont();
        $storage->addAttributeValueRange(NSFontAttributeName, $font, $all);
        $single = NSNumber::numberWithInteger(NSUnderlineStyle::SINGLE->value);
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
            if ($style->bold || $style->italic) {
                $run = $style->bold ? self::withTrait($font, NSBoldFontMask) : $font;
                $storage->addAttributeValueRange(NSFontAttributeName, $style->italic ? self::withTrait($run, NSItalicFontMask) : $run, $range);
            }
            if ($style->underline) {
                $storage->addAttributeValueRange(NSUnderlineStyleAttributeName, $single, $range);
            }
            if ($style->strikeout) {
                $storage->addAttributeValueRange(NSStrikethroughStyleAttributeName, $single, $range);
            }
        }
        $this->write(fn () => $storage->endEditing());
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
            [$glyphs] = $manager->glyphRangeForCharacterRangeActualCharacterRange(new NSRange($start, 1));
            $top = $manager->boundingRectForGlyphRangeInTextContainer($glyphs, $this->text->textContainer())->y
                + $this->text->textContainerInset()->height;
        }
        $this->scrollClipTo($visible->x, max(0.0, min($top, $content->height - $visible->height)));
    }

    protected function releaseNative(): void
    {
        $this->text->setDelegate(null);
        $this->delegate = null;
        if (! is_null($this->scroll_observer)) {
            NSNotificationCenter::defaultCenter()->removeObserver($this->scroll_observer);
            $this->scroll_observer = null;
        }
        $this->releaseView();
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
        $this->write(function () use ($x, $y): void {
            $this->clip->scrollToPoint(new NSPoint($x, $y));
            $this->scrollNative()->reflectScrolledClipView($this->clip);
        });
    }

    private function areaFont(): NSFont
    {
        return is_null($this->font) ? $this->built_font : self::nsFont($this->font);
    }

    /** A new style with the area's alignment and its tab width as default stops (no explicit ones). */
    private function paragraphStyle(): NSMutableParagraphStyle
    {
        $style = NSMutableParagraphStyle::init();
        $style->setAlignment(self::nsAlignment($this->alignment));
        $style->setTabStops([]);
        $style->setDefaultTabInterval($this->tab_width * NSString::stringWithString(' ')->sizeWithAttributes([NSFontAttributeName => $this->areaFont()])->width);

        return $style;
    }

    private function foreground(): NSColor
    {
        return is_null($this->text_color) ? ($this->built_color ?? NSColor::colorWithRedGreenBlueAlpha(0.0, 0.0, 0.0, 1.0)) : self::nsColor($this->text_color);
    }

    private function scrollNative(): NSScrollView
    {
        /** @var NSScrollView */
        return $this->native();
    }

    private static function withTrait(NSFont $font, int $trait): NSFont
    {
        return NSFontManager::sharedFontManager()->convertFontToHaveTrait($font, $trait);
    }
}
