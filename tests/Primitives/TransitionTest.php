<?php

declare(strict_types=1);

use Surface\Contracts\Windows\Primitives\TKTransition;

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

/** The opacity the view's layer is drawn at right now (its presentation layer's), or null with no committed layer. */
function drawnOpacity(NSView $view): ?float
{
    return $view->layer()?->presentationLayer()?->opacity();
}

it('is drawn part-way through a fade, in a window already on screen', function (): void {
    $window = driver()->open('main', 400, 300);
    $main = $window->column('main', spacing: 8, padding: 10);
    $main->label('top', 'Top');
    $card = $main->column('card', padding: 12);
    $card->label('text', 'Card');
    $window->present();
    pumpFor(0.2);

    $card->appear(TKTransition::FADE, 0.6)->hide();
    pumpFor(0.1);
    $card->show();
    pumpFor(0.3);

    expect(drawnOpacity($card->ownView()))->toBeGreaterThan(0.05)->toBeLessThan(0.95);

    pumpFor(0.6);

    expect(drawnOpacity($card->ownView()))->toEqualWithDelta(1.0, 0.001);
});

it('is drawn part-way through a fade when it is shown before the window is on screen', function (): void {
    $window = driver()->open('main', 400, 300);
    $main = $window->column('main', spacing: 8, padding: 10);
    $card = $main->column('card', padding: 12);
    $card->label('text', 'Card');
    $card->appear(TKTransition::FADE, 0.6)->hide();

    $window->present();
    $card->show();
    pumpFor(0.3);

    expect(drawnOpacity($card->ownView()))->toBeGreaterThan(0.05)->toBeLessThan(0.95);
});

it('is drawn part-way through a fade out', function (): void {
    $window = driver()->open('main', 400, 300);
    $label = $window->column('main')->label('a', 'A');
    $window->present();
    pumpFor(0.2);

    $label->disappear(TKTransition::FADE, 0.6)->hide();
    pumpFor(0.3);

    expect(drawnOpacity($label->ownView()))->toBeGreaterThan(0.05)->toBeLessThan(0.95);
});

it('draws a view at the opacity it is given', function (): void {
    $window = driver()->open('main', 400, 300);
    $label = $window->column('main')->label('a', 'A');
    $window->present();

    $label->setOpacity(0.4);

    expect($label->ownView()->alphaValue())->toEqualWithDelta(0.4, 0.001)
        ->and($label->opacity())->toBe(0.4);
});

it('shows the native at once when it appears and lands opaque where layout put it', function (): void {
    $window = driver()->open('main', 400, 300);
    $main = $window->column('main', spacing: 8, padding: 10);
    $main->label('top', 'Top');
    $card = $main->column('card', padding: 12);
    $card->label('text', 'Card');
    $window->present();
    pumpFor(0.2);
    $rest = $card->ownView()->frame();

    $card->appear(TKTransition::SLIDE_UP, 0.2)->hide();
    pumpFor(0.1);
    $card->show();

    expect($card->ownView()->isHidden())->toBeFalse();

    pumpFor(0.5);
    $frame = $card->ownView()->frame();

    expect($card->ownView()->alphaValue())->toEqualWithDelta(1.0, 0.001)
        ->and($frame->x)->toEqualWithDelta($rest->x, 0.5)
        ->and($frame->y)->toEqualWithDelta($rest->y, 0.5);
});

it('keeps the native up while it disappears, then hides it and puts its alpha back', function (): void {
    $window = driver()->open('main', 400, 300);
    $label = $window->column('main')->label('a', 'A');
    $window->present();
    pumpFor(0.2);

    $label->disappear(TKTransition::FADE, 0.2)->hide();

    expect($label->isVisible())->toBeFalse()
        ->and($label->ownView()->isHidden())->toBeFalse();

    pumpFor(0.5);

    expect($label->ownView()->isHidden())->toBeTrue()
        ->and($label->ownView()->alphaValue())->toEqualWithDelta(1.0, 0.001);
});

it('stays shown when shown again before its disappearance lands', function (): void {
    $window = driver()->open('main', 400, 300);
    $label = $window->column('main')->label('a', 'A');
    $window->present();
    pumpFor(0.2);

    $label->disappear(TKTransition::FADE, 0.3)->appear(TKTransition::FADE, 0.1);
    $label->hide();
    pumpFor(0.05);
    $label->show();
    pumpFor(0.6);

    expect($label->isVisible())->toBeTrue()
        ->and($label->ownView()->isHidden())->toBeFalse()
        ->and($label->ownView()->alphaValue())->toEqualWithDelta(1.0, 0.001);
});
