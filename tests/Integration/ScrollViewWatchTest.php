<?php

declare(strict_types=1);

use Surface\Contracts\Toolkits\Mail\Views\ViewScrolled;

/** A 400x300 window whose scroll view holds 60 labels: more than fits. */
function tallScroll(): object
{
    $window = openWindow('scroll', 400, 300);
    $scroll = $window->column('root')->scrollView('page');
    $scroll->fill();
    $content = $scroll->column('content');
    for ($i = 0; $i < 60; $i++) {
        $content->label("line{$i}", "Line {$i}");
    }
    pumpFor(0.3);
    takeMail();

    return $scroll;
}

/** @return list<ViewScrolled> */
function scrolls(array $mail): array
{
    return array_values(array_filter($mail, fn (object $m): bool => $m instanceof ViewScrolled));
}

it('starts at the top, showing part of the content', function (): void {
    $at = tallScroll()->scrollPosition();

    expect($at->y)->toBe(0.0)
        ->and($at->height)->toBeGreaterThan(0.0)
        ->and($at->height)->toBeLessThan(1.0);
});

it('posts nothing for a scroll while it is not watched', function (): void {
    $scroll = tallScroll();

    scrollToEnd($scroll);
    pumpFor(0.3);

    expect(scrolls(takeMail()))->toBe([]);
});

it('posts where it is when scrolled, the bottom reading 1.0', function (): void {
    $scroll = tallScroll();
    $scroll->watchScroll();

    scrollToEnd($scroll);
    $posted = scrolls(mailUntil('ViewScrolled'));
    pumpFor(0.2);
    $posted = [...$posted, ...scrolls(takeMail())];
    $last = end($posted);

    expect($posted)->not->toBe([])
        ->and($last->path)->toBe($scroll->path())
        ->and(round($last->position->y + $last->position->height, 2))->toBe(1.0)
        ->and($last->position->y)->toBeGreaterThan(0.5)
        ->and(round($scroll->scrollPosition()->y, 3))->toBe(round($last->position->y, 3));
});

/** Scroll the native to its end, as the user's wheel does: the clip view moves. */
function scrollToEnd(object $scroll): void
{
    $native = $scroll->native();
    $clip = $native->contentView();
    $document = $native->documentView();
    // The document's last edge, in the clip view's coordinates: its bottom, wherever y counts from.
    $frame = $document->frame();
    $clip->scrollToPoint(new NSPoint(0.0, $clip->isFlipped() ? $frame->y + $frame->height - $clip->bounds()->height : $frame->y));
    $native->reflectScrolledClipView($clip);
}
