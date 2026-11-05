<?php

declare(strict_types=1);

use Jovian\Toolkits\Appkit\Primitives\Concerns\AppkitFonts;
use Surface\Contracts\Windows\Styling\FontSpec;
use Surface\Contracts\Windows\Styling\TextAlignment;
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

function traitsAt(NSTextStorage $storage, int $at): int
{
    return NSFontManager::sharedFontManager()->traitsOfFont($storage->attributeAtIndexEffectiveRange(NSFontAttributeName, $at)[0]);
}

it('measures the monospace font and a family the system lacks', function (): void {
    $mono = driver()->fontMetrics(new FontSpec(13.0, monospace: true));
    $missing = driver()->fontMetrics(new FontSpec(13.0, family: 'Zzqq Sans'));

    expect($mono->fixedPitch)->toBeTrue()
        ->and($mono->ascent)->toBeGreaterThan(0.0)
        ->and($mono->descent)->toBeGreaterThan(0.0)
        ->and($mono->averageCharWidth)->toBeGreaterThan(0.0)
        ->and($mono->lineHeight)->toBeGreaterThanOrEqual($mono->ascent + $mono->descent)
        ->and($missing->ascent)->toBeGreaterThan(0.0)
        ->and($missing->fixedPitch)->toBeFalse();
});

it('makes an italic font on a label, from the system font and from a family', function (): void {
    $column = driver()->open('main', 300, 200)->column('m');
    $system = $column->label('a', 'x')->setFont(new FontSpec(13.0, italic: true));
    $named = $column->label('b', 'x')->setFont(new FontSpec(13.0, family: 'Menlo', italic: true));

    expect(NSFontManager::sharedFontManager()->traitsOfFont($system->native()->font()) & NSItalicFontMask)->toBe(NSItalicFontMask)
        ->and(NSFontManager::sharedFontManager()->traitsOfFont($named->native()->font()) & NSItalicFontMask)->toBe(NSItalicFontMask);
});

it('styles runs bold, italic, underlined and struck, a flag-only run keeps the area colour, and new runs clear old flags', function (): void {
    $area = driver()->open('main', 300, 200)->column('m')->textArea('t', "bold italic under strike")->fill();
    $area->setRangeStyles([
        new TextRangeStyle(0, 0, 4, bold: true, underline: true),
        new TextRangeStyle(0, 5, 6, italic: true),
        new TextRangeStyle(0, 12, 5, underline: true),
        new TextRangeStyle(0, 18, 6, strikeout: true),
    ]);
    $storage = $area->native()->documentView()->textStorage();
    $plain = $storage->attributeAtIndexEffectiveRange(NSForegroundColorAttributeName, 4)[0];

    expect(traitsAt($storage, 1) & NSBoldFontMask)->toBe(NSBoldFontMask)
        ->and(traitsAt($storage, 6) & NSItalicFontMask)->toBe(NSItalicFontMask)
        ->and(traitsAt($storage, 4) & (NSBoldFontMask | NSItalicFontMask))->toBe(0)
        ->and($storage->attributeAtIndexEffectiveRange(NSUnderlineStyleAttributeName, 1)[0])->toBe(NSUnderlineStyle::SINGLE->value)
        ->and($storage->attributeAtIndexEffectiveRange(NSUnderlineStyleAttributeName, 13)[0])->toBe(NSUnderlineStyle::SINGLE->value)
        ->and($storage->attributeAtIndexEffectiveRange(NSUnderlineStyleAttributeName, 6)[0])->toBeNull()
        ->and($storage->attributeAtIndexEffectiveRange(NSStrikethroughStyleAttributeName, 19)[0])->toBe(NSUnderlineStyle::SINGLE->value)
        ->and($storage->attributeAtIndexEffectiveRange(NSForegroundColorAttributeName, 6)[0])->toBe($plain);

    $area->setRangeStyles([new TextRangeStyle(0, 0, 1, foreground: Color::hex('#ff0000'))]);
    expect(traitsAt($storage, 1) & NSBoldFontMask)->toBe(0)
        ->and($storage->attributeAtIndexEffectiveRange(NSUnderlineStyleAttributeName, 1)[0])->toBeNull()
        ->and($storage->attributeAtIndexEffectiveRange(NSStrikethroughStyleAttributeName, 19)[0])->toBeNull();
});

it('keeps bold runs and tab stops through a font change', function (): void {
    $area = driver()->open('main', 300, 200)->column('m')->textArea('t', "a\tb\nc")->fill();
    $area->setFont(new FontSpec(13.0, monospace: true))->setTabWidth(4)
        ->setRangeStyles([new TextRangeStyle(0, 0, 1, bold: true)]);
    $view = $area->native()->documentView();
    $storage = $view->textStorage();
    $space = fn (float $size): float => AppkitFonts::spaceAdvance(AppkitFonts::nsFont(new FontSpec($size, monospace: true)));

    expect($storage->attributeAtIndexEffectiveRange(NSParagraphStyleAttributeName, 0)[0]->defaultTabInterval())->toEqualWithDelta(4 * $space(13.0), 0.01)
        ->and($storage->attributeAtIndexEffectiveRange(NSParagraphStyleAttributeName, 0)[0]->tabStops())->toBe([])
        ->and($view->defaultParagraphStyle()->defaultTabInterval())->toEqualWithDelta(4 * $space(13.0), 0.01);

    $area->setFont(new FontSpec(18.0, monospace: true));
    expect($storage->attributeAtIndexEffectiveRange(NSParagraphStyleAttributeName, 4)[0]->defaultTabInterval())->toEqualWithDelta(4 * $space(18.0), 0.01)
        ->and(traitsAt($storage, 0) & NSBoldFontMask)->toBe(NSBoldFontMask)
        ->and($storage->attributeAtIndexEffectiveRange(NSFontAttributeName, 0)[0]->pointSize())->toBe(18.0);
});

it('starts a text area at 8 columns and keeps tab stops and alignment on background lines', function (): void {
    $area = driver()->open('main', 300, 200)->column('m')->textArea('t', "a\tb\nc")->fill();
    $storage = $area->native()->documentView()->textStorage();
    $space = AppkitFonts::spaceAdvance($area->native()->documentView()->font());

    expect($storage->attributeAtIndexEffectiveRange(NSParagraphStyleAttributeName, 0)[0]->defaultTabInterval())->toEqualWithDelta(8 * $space, 0.01);

    $area->setAlignment(TextAlignment::RIGHT)->setLineBackgrounds([1 => Color::hex('#ff0000')]);
    $line = $storage->attributeAtIndexEffectiveRange(NSParagraphStyleAttributeName, 4)[0];
    expect($line->alignment())->toBe(NSTextAlignment::RIGHT)
        ->and($line->textBlocks())->toHaveCount(1)
        ->and($line->defaultTabInterval())->toEqualWithDelta(8 * $space, 0.01)
        ->and($storage->attributeAtIndexEffectiveRange(NSParagraphStyleAttributeName, 0)[0]->alignment())->toBe(NSTextAlignment::RIGHT)
        ->and($area->native()->documentView()->defaultParagraphStyle()->alignment())->toBe(NSTextAlignment::RIGHT);
});

it('aligns a button and an input', function (): void {
    $column = driver()->open('main', 300, 200)->column('m');
    $button = $column->button('b', 'Go')->setAlignment(TextAlignment::RIGHT);
    $input = $column->textInput('i', '12')->setAlignment(TextAlignment::CENTER);

    expect($button->native()->alignment())->toBe(NSTextAlignment::RIGHT)
        ->and($input->native()->alignment())->toBe(NSTextAlignment::CENTER);
});
