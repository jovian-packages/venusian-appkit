<?php

declare(strict_types=1);

use Jovian\Toolkits\Appkit\Primitives\AppkitWebView;
use Surface\Contracts\Windows\Mail\View\WebViewFailed;
use Surface\Contracts\Windows\Mail\View\WebViewLoaded;

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

/** Pump, collecting mail, until mail of $class arrives or $seconds pass. */
function webMailUntil(string $class, float $seconds): array
{
    $until = microtime(true) + $seconds;
    $mail = [];
    while (microtime(true) < $until) {
        pumpFor(0.05);
        $mail = [...$mail, ...takeMail(session())];
        if (array_filter($mail, fn (object $m): bool => $m instanceof $class)) {
            break;
        }
    }

    return $mail;
}

it('loads HTML with a base URL, posts WebViewLoaded and reports url and title', function (): void {
    $window = driver()->open('main', 320, 240);
    $web = $window->column('m')->webView('w');
    $web->fill();
    $window->present();
    $web->loadHtml('<title>Hello</title><p>hi</p>', 'https://example.test/page');
    $mail = webMailUntil(WebViewLoaded::class, 10.0);

    expect($web)->toBeInstanceOf(AppkitWebView::class)
        ->and($mail)->toContainEqual(new WebViewLoaded('main', 'm.w', $web->uuid(), 'https://example.test/page'))
        ->and($web->url())->toBe('https://example.test/page')
        ->and(pumpUntil(fn () => $web->title() === 'Hello', 5.0))->toBeTrue()
        ->and($web->isLoading())->toBeFalse();
});

it('posts WebViewFailed with the engine\'s reason for a page it cannot load', function (): void {
    $window = driver()->open('main', 200, 200);
    $web = $window->column('m')->webView('w');
    $web->load('file:///nonexistent/surface-web-view.html');
    $mail = webMailUntil(WebViewFailed::class, 10.0);
    $failed = array_values(array_filter($mail, fn (object $m): bool => $m instanceof WebViewFailed));

    expect($failed)->toHaveCount(1)
        ->and($failed[0]->path())->toBe('m.w')
        ->and($failed[0]->reason)->not->toBe('')
        ->and($web->isLoading())->toBeFalse();
});
