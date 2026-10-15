<?php

declare(strict_types=1);

use Jovian\Toolkits\Appkit\Primitives\AppkitLink;
use Surface\Contracts\Windows\Styling\FontSpec;
use Surface\Contracts\Windows\Styling\FontWeight;
use Surface\Contracts\Windows\Styling\TextAlignment;

beforeEach(function (): void {
    driver()->closeAll();
    pumpFor(0.05);
    takeMail(session());
});

/** @return array{string, mixed} the label's text and its link attribute at the first character */
function shownLink(AppkitLink $link): array
{
    $shown = $link->native()->attributedStringValue();
    $target = $shown->attributeAtIndex(NSLinkAttributeName, 0);

    return [$shown->string(), $target instanceof NSURL ? $target->absoluteString() : $target];
}

it('shows a link as a selectable label whose attributed text carries the URL and the font', function (): void {
    $column = driver()->open('main', 300, 200)->column('m');
    $link = $column->link('docs', 'Read the docs', 'https://example.com/docs');
    $empty = $column->link('empty', '', 'https://example.com/');
    $spaced = $column->link('spaced', 'Spaced', 'https://example.com/a b');

    expect($link)->toBeInstanceOf(AppkitLink::class)
        ->and(shownLink($link))->toBe(['Read the docs', 'https://example.com/docs'])
        ->and([$link->native()->isSelectable(), $link->native()->allowsEditingTextAttributes(), $link->native()->isEditable()])->toBe([true, true, false])
        ->and($empty->native()->attributedStringValue()->length())->toBe(0)
        ->and(shownLink($spaced)[1])->not->toBeNull();

    $link->setText('Docs')->setUrl('mailto:help@example.com')->setFont(new FontSpec(15.0, FontWeight::BOLD, 'Helvetica'))->setAlignment(TextAlignment::RIGHT);

    expect(shownLink($link))->toBe(['Docs', 'mailto:help@example.com'])
        ->and($link->native()->attributedStringValue()->attributeAtIndex(NSFontAttributeName, 0)->pointSize())->toBe(15.0)
        ->and($link->native()->font()->pointSize())->toBe(15.0)
        ->and($link->native()->alignment())->toBe(NSTextAlignment::RIGHT)
        ->and($link->native()->attributedStringValue()->attributeAtIndex(NSParagraphStyleAttributeName, 0)->alignment())->toBe(NSTextAlignment::RIGHT)
        ->and(takeMail(session()))->toBe([]);
});

it('disables a link in a disabled group', function (): void {
    $column = driver()->open('main', 300, 200)->column('m');
    $link = $column->link('docs', 'Docs', 'https://example.com/');
    $column->disable();

    expect($link->native()->isEnabled())->toBeFalse();
});

it('draws a link without a set font in the label font, as a label next to it is drawn', function (): void {
    $column = driver()->open('main', 300, 200)->column('m');
    $label = $column->label('l', 'Docs');
    $link = $column->link('docs', 'Docs', 'https://example.com/docs');
    $font = $link->native()->attributedStringValue()->attributeAtIndex(NSFontAttributeName, 0);

    expect($font)->toBeInstanceOf(NSFont::class)
        ->and([$font->familyName(), $font->pointSize()])->toBe([$label->native()->font()->familyName(), $label->native()->font()->pointSize()]);
});
