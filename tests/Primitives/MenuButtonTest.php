<?php

declare(strict_types=1);

use Surface\Contracts\Framebuffers\FormatSpec;
use Surface\Contracts\Windows\Mail\MenuActivated;
use Surface\Framebuffers\Native\NativeFullFramebuffer;

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

it('is a pull-down: item 0 holds the label, the menu items follow', function (): void {
    $window = driver()->open('main', 300, 200);
    $button = $window->column('m')->menuButton('case', 'Ignore case', [['label' => 'None'], ['label' => 'Unimportant'], ['label' => 'All']]);
    $titles = $button->native()->itemTitles();

    expect($button->native()->numberOfItems())->toBe(4)
        ->and($titles)->toBe(['Ignore case', 'None', 'Unimportant', 'All']);

    $button->setLabel('Case');
    expect($button->native()->itemTitles()[0])->toBe('Case');
    $window->close();
});

it('posts MenuActivated for a chosen item', function (): void {
    $window = driver()->open('main', 300, 200);
    $button = $window->column('m')->menuButton('case', 'Ignore case', [['label' => 'None'], ['label' => 'All']]);

    $button->native()->menu()->performActionForItemAtIndex(2);
    pumpFor(0.05);

    expect(takeMail(session()))->toEqual([new MenuActivated('main', 'all')]);
    $window->close();
});

it('rebuilds its items for a new menu, and shows an image and no bezel', function (): void {
    $window = driver()->open('main', 300, 200);
    $button = $window->column('m')->menuButton('case', 'Ignore case', [['label' => 'None']]);

    $button->setMenu([['label' => 'A'], ['label' => 'B']]);
    $button->setImage((new NativeFullFramebuffer(FormatSpec::rgba8(), 32, 32))->fill(0xffffffff), 2.0);
    $button->setBordered(false);

    expect($button->native()->itemTitles())->toBe(['Ignore case', 'A', 'B'])
        ->and($button->native()->menu()->itemAtIndex(0)->image()->size()->width)->toBe(16.0)
        ->and($button->native()->isBordered())->toBeFalse();
    $window->close();
});

it('keeps a radio choice and a toggle made in its menu', function (): void {
    $window = driver()->open('main', 300, 200);
    $button = $window->column('m')->menuButton('case', 'Case', [
        ['id' => 'case.none', 'label' => 'None', 'radio' => 'case', 'on' => true],
        ['id' => 'case.all', 'label' => 'All', 'radio' => 'case'],
        ['id' => 'wrap', 'label' => 'Wrap', 'toggle' => true],
    ]);
    $menu = $button->native()->menu();

    $menu->performActionForItemAtIndex(2);
    $menu->performActionForItemAtIndex(3);
    pumpFor(0.05);

    expect(takeMail(session()))->toEqual([new Surface\Contracts\Windows\Mail\MenuRadioChosen('main', 'case', 'case.all'), new Surface\Contracts\Windows\Mail\MenuToggled('main', 'wrap', true)])
        ->and($button->menu()->radios())->toBe(['case' => 'case.all'])
        ->and($button->menu()->items()['wrap']->isOn())->toBeTrue()
        ->and($menu->itemAtIndex(2)->state())->toBe(NSControlStateValue::ON)
        ->and($menu->itemAtIndex(1)->state())->toBe(NSControlStateValue::OFF);
    $window->close();
});
