<?php

declare(strict_types=1);

use Surface\Contracts\Windows\Mail\View\ViewScrolled;
use Surface\Contracts\Windows\Styling\FontSpec;
use Surface\Contracts\Windows\Styling\TextRangeStyle;
use Surface\NutsAndBolts\Color;

beforeEach(function (): void {
    driver()->closeAll();
    pumpFor(0.05);
    takeMail(session());
});

afterEach(function (): void {
    driver()->closeAll();
    pumpFor(0.05);
    takeMail(session());
});

it('paints line backgrounds as text blocks and ranges as colour attributes, and clears them with a new value', function (): void {
    $area = driver()->open('main', 300, 200)->column('m')->textArea('diff', "one\ntwo\nthrée😀x")->fill();
    $area->setLineBackgrounds([1 => Color::hex('#ff0000')])
        ->setRangeStyles([new TextRangeStyle(2, 4, 2, foreground: Color::hex('#0000ff'), background: Color::hex('#ffff00'))]);
    $storage = $area->native()->documentView()->textStorage();
    $block = $storage->attributeAtIndex(NSParagraphStyleAttributeName, 4)->textBlocks()[0];

    expect(round($block->backgroundColor()->redComponent(), 2))->toBe(1.0)
        ->and($block->valueForDimension(NSTextBlockDimension::WIDTH))->toBe(100.0)
        ->and($storage->attributeAtIndex(NSParagraphStyleAttributeName, 0))->toBeNull()
        ->and($area->native()->documentView()->layoutManager()->allowsNonContiguousLayout())->toBeTrue()
        ->and(round($storage->attributeAtIndex(NSForegroundColorAttributeName, 12)->blueComponent(), 2))->toBe(1.0)
        ->and(round($storage->attributeAtIndex(NSBackgroundColorAttributeName, 13)->greenComponent(), 2))->toBe(1.0)   // the 😀's first unit
        ->and($storage->attributeAtIndex(NSBackgroundColorAttributeName, 11))->toBeNull()
        ->and($storage->attributeAtIndex(NSBackgroundColorAttributeName, 15))->toBeNull();

    $area->setTextColor(Color::hex('#00ff00'));
    expect(round($storage->attributeAtIndex(NSForegroundColorAttributeName, 12)->blueComponent(), 2))->toBe(1.0)
        ->and(round($storage->attributeAtIndex(NSForegroundColorAttributeName, 0)->greenComponent(), 2))->toBe(1.0);

    $area->native()->documentView()->setSelectedRange(new NSRange(4, 0));   // the caret inside the styled line
    $area->setValue("x\ny");
    expect($storage->attributeAtIndex(NSParagraphStyleAttributeName, 0))->toBeNull()
        ->and($storage->attributeAtIndex(NSBackgroundColorAttributeName, 2))->toBeNull()
        ->and($area->native()->documentView()->string())->toBe("x\ny")
        ->and(viewMail(session()))->toBe([]);
});

it('puts range foregrounds back where a user edit moved them when the text colour changes', function (): void {
    $window = driver()->open('main', 300, 200);
    $area = $window->column('m')->textArea('diff', "one\ntwo\nthree")->fill();
    $area->setRangeStyles([new TextRangeStyle(2, 0, 5, foreground: Color::hex('#0000ff'))]);
    $text = $area->native()->documentView();
    $window->present();
    expect(pumpUntil(fn () => $window->isKey(), 5.0))->toBeTrue();
    $window->native()->makeFirstResponder($text);
    $text->setSelectedRange(new NSRange(0, 4));   // "one\n"
    session()->application()->postEventAtStart(NSEvent::keyEventWithTypeLocationModifierFlagsTimestampWindowNumberContextCharactersCharactersIgnoringModifiersIsARepeatKeyCode(
        NSEventType::KEY_DOWN, new NSPoint(), 0, 0.0, $window->native()->windowNumber(), null, "\u{7f}", "\u{7f}", false, 0x33,
    ), false);
    pumpFor(0.1);
    $window->native()->makeFirstResponder(null);
    $edited = $text->string();

    $area->setTextColor(Color::hex('#00ff00'));
    $storage = $text->textStorage();

    expect($edited)->toBe("two\nthree")
        ->and(round($storage->attributeAtIndex(NSForegroundColorAttributeName, 4)->blueComponent(), 2))->toBe(1.0)
        ->and(round($storage->attributeAtIndex(NSForegroundColorAttributeName, 8)->blueComponent(), 2))->toBe(1.0)
        ->and(round($storage->attributeAtIndex(NSForegroundColorAttributeName, 0)->greenComponent(), 2))->toBe(1.0)
        ->and(round($storage->attributeAtIndex(NSForegroundColorAttributeName, 3)->greenComponent(), 2))->toBe(1.0);
});

it('selects lines over UTF-16 offsets and reads the selection back as lines', function (): void {
    $area = driver()->open('main', 300, 200)->column('m')->textArea('diff', "é😀\ntwo\nthree");
    $text = $area->native()->documentView();

    $area->selectLines(1, 2);
    expect([$text->selectedRange()->location, $text->selectedRange()->length])->toBe([4, 9])
        ->and($area->selectedLines())->toBe([1, 2])
        ->and($area->caretLine())->toBe(1);

    $text->setSelectedRange(new NSRange(4, 4));   // "two\n": the newline belongs to line 1
    expect($area->selectedLines())->toBe([1, 1]);
    $text->setSelectedRange(new NSRange(2, 0));
    expect($area->selectedLines())->toBe([0, 0])
        ->and(viewMail(session()))->toBe([]);
});

it('stops wrapping, turns read-only and monospace on, and keeps a disabled area uneditable', function (): void {
    $area = driver()->open('main', 300, 200)->column('m')->textArea('diff', 'text');
    $text = $area->native()->documentView();
    $area->setWrap(false)->setReadOnly(true)->setFont(new FontSpec(12.0, monospace: true));

    expect($area->native()->hasHorizontalScroller())->toBeTrue()
        ->and($text->textContainer()->widthTracksTextView())->toBeFalse()
        ->and($text->isHorizontallyResizable())->toBeTrue()
        ->and($text->isEditable())->toBeFalse()
        ->and($text->font()->familyName())->toBe(NSFont::monospacedSystemFontOfSizeWeight(12.0, NSFont::WEIGHT_REGULAR)->familyName());

    $area->setWrap(true)->setReadOnly(false);
    expect($area->native()->hasHorizontalScroller())->toBeFalse()
        ->and($text->textContainer()->widthTracksTextView())->toBeTrue()
        ->and($text->isEditable())->toBeTrue();

    $area->disable();
    expect($text->isEditable())->toBeFalse();
    $area->enable()->setReadOnly(true);
    expect($text->isEditable())->toBeFalse();
    $area->enable();
    expect($text->isEditable())->toBeFalse();
});

it('scrolls by content fractions and to a line, posting ViewScrolled only for scrolls it did not make', function (): void {
    $window = driver()->open('main', 300, 200);
    $area = $window->column('m')->textArea('diff', implode("\n", range(1, 400)))->fill()->setWrap(false)->watchScroll();
    $window->present();
    expect(settled($window, $area))->toBeTrue();
    takeMail(session());

    $area->scrollTo(0.0, 0.5);
    pumpFor(0.1);
    $at = $area->scrollPosition();
    expect(viewMail(session()))->toBe([])
        ->and($at->y)->toEqualWithDelta(0.5, 0.01)
        ->and($at->height)->toBeLessThan(0.5);

    $clip = $area->native()->contentView();
    $before = $clip->bounds();
    $area->scrollTo(0.0, $at->y);   // the position it has: nothing moves, nothing posts
    pumpFor(0.1);
    expect([$clip->bounds()->x, $clip->bounds()->y])->toBe([$before->x, $before->y])
        ->and(viewMail(session()))->toBe([]);
    $area->scrollTo(0.0, $at->y + 0.4 / $area->native()->documentView()->frame()->height);   // float drift under half a point
    expect([$clip->bounds()->x, $clip->bounds()->y])->toBe([$before->x, $before->y]);

    $clip->scrollToPoint(new NSPoint(0.0, 100.0));
    $area->native()->reflectScrolledClipView($clip);
    pumpFor(0.1);
    $mail = viewMail(session());
    expect($mail)->toHaveCount(1)
        ->and($mail[0])->toBeInstanceOf(ViewScrolled::class)
        ->and($mail[0]->position)->toEqual($area->scrollPosition());

    $area->scrollToLine(200);
    pumpFor(0.1);
    expect($area->scrollPosition()->y)->toEqualWithDelta(0.5, 0.01)
        ->and(viewMail(session()))->toBe([]);

    $area->watchScroll(false);
    $clip->scrollToPoint(new NSPoint(0.0, 0.0));
    $area->native()->reflectScrolledClipView($clip);
    pumpFor(0.1);
    expect(viewMail(session()))->toBe([]);
});

it('posts no ViewScrolled for a font change that relayouts the text and clamps the scroll', function (): void {
    $window = driver()->open('main', 300, 200);
    $area = $window->column('m')->textArea('diff', implode("\n", range(1, 400)))->fill()->setWrap(false)->watchScroll();
    $window->present();
    expect(settled($window, $area))->toBeTrue();
    $area->scrollTo(0.0, 1.0);
    expect(settled($window, $area))->toBeTrue();
    takeMail(session());
    $bottom = $area->native()->contentView()->bounds()->y;

    $area->setFont(new FontSpec(6.0, monospace: true));
    pumpFor(0.1);
    expect($area->native()->contentView()->bounds()->y)->toBeLessThan($bottom)
        ->and(viewMail(session()))->toBe([]);

    $area->setFont(new FontSpec(18.0));
    pumpFor(0.1);
    expect(viewMail(session()))->toBe([]);
});

it('posts no ViewScrolled for a new value or a wrap change that clamps the scroll', function (): void {
    $window = driver()->open('main', 300, 200);
    $area = $window->column('m')->textArea('diff', implode("\n", range(1, 400)))->fill()->setWrap(false)->watchScroll();
    $window->present();
    expect(settled($window, $area))->toBeTrue();
    $area->scrollTo(0.0, 1.0);
    expect(settled($window, $area))->toBeTrue();
    takeMail(session());
    $clip = $area->native()->contentView();
    $bottom = $clip->bounds()->y;

    $area->setValue(implode("\n", range(1, 100)));
    pumpFor(0.1);
    expect($clip->bounds()->y)->toBeLessThan($bottom)
        ->and(viewMail(session()))->toBe([]);

    $area->setValue(str_repeat('wide ', 400) . "\n" . implode("\n", range(1, 100)));
    $area->scrollTo(1.0, 1.0);
    pumpFor(0.1);
    $right = $clip->bounds()->x;
    expect($right)->toBeGreaterThan(0.0);

    $area->setWrap(true);
    pumpFor(0.1);
    expect($clip->bounds()->x)->toBe(0.0)
        ->and(viewMail(session()))->toBe([]);
});

it('keeps a no-wrap area with line backgrounds as wide as its widest line, and scrolls it sideways', function (): void {
    $window = driver()->open('main', 300, 200);
    $area = $window->column('m')->textArea('diff', "one\n" . str_repeat('wide ', 100) . "\nthree")->fill();
    $area->setWrap(false)->setLineBackgrounds([0 => Color::hex('#ff0000'), 2 => Color::hex('#00ff00')]);
    $window->present();
    expect(settled($window, $area))->toBeTrue();
    $text = $area->native()->documentView();
    $clip = $area->native()->contentView();
    $layout = $text->layoutManager();
    $width = $text->frame()->width;

    // A 100 % block in the unbounded no-wrap container does not widen the laid-out text.
    expect($text->textStorage()->attributeAtIndex(NSParagraphStyleAttributeName, 0)->textBlocks())->toHaveCount(1)
        ->and($width)->toBeGreaterThan($clip->bounds()->width)
        ->and($width)->toBeLessThan(10 * $clip->bounds()->width)
        ->and($layout->usedRectForTextContainer($text->textContainer())->width)->toBeLessThanOrEqual($width)
        ->and($area->scrollPosition()->width)->toBeGreaterThan(0.05);

    $area->scrollTo(1.0, 0.0);
    expect($clip->bounds()->x + $clip->bounds()->width)->toEqualWithDelta($width, 0.5)
        ->and($area->scrollPosition()->x + $area->scrollPosition()->width)->toEqualWithDelta(1.0, 0.001);

    $area->setWrap(true);
    $block = $text->textStorage()->attributeAtIndex(NSParagraphStyleAttributeName, 0)->textBlocks()[0];
    expect($block->valueTypeForDimension(NSTextBlockDimension::WIDTH))->toBe(NSTextBlockValueType::PERCENTAGE)
        ->and($block->valueForDimension(NSTextBlockDimension::WIDTH))->toBe(100.0)
        ->and($text->frame()->width)->toBeLessThanOrEqual($clip->bounds()->width);
});
