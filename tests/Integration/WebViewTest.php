<?php

declare(strict_types=1);

use Jovian\Toolkits\Appkit\UI\AppkitWebView;
use Surface\Contracts\Toolkits\Mail\Views\WebViewFailed;
use Surface\Contracts\Toolkits\Mail\Views\WebViewLoaded;

/** A window with one web view in its content column. */
function webViewWindow(): array
{
    $window = openWindow('web', 400, 300);
    $web = $window->column('root')->webView('player');
    pumpFor(0.1);
    takeMail();

    return [$window, $web];
}

it('builds a WKWebView in the primitive map', function (): void {
    [, $web] = webViewWindow();

    expect($web)->toBeInstanceOf(AppkitWebView::class)
        ->and($web->native())->toBeInstanceOf(WKWebView::class)
        ->and($web->supported())->toBeTrue()
        ->and($web->url())->toBeNull()
        ->and($web->title())->toBeNull()
        ->and($web->isLoading())->toBeFalse();
});

it('loads HTML with a base URL, posts WebViewLoaded with it and reads the page back', function (): void {
    [, $web] = webViewWindow();
    $web->loadHtml('<title>Hello</title><p>hi</p>', 'https://example.test/page');

    $mail = mailUntil('WebViewLoaded', 10.0);
    $loaded = array_values(array_filter($mail, fn (object $m): bool => $m instanceof WebViewLoaded));
    // WebKit's title is a KVO property that can trail the finish by a moment.
    pumpUntil(fn (): bool => $web->title() !== null, 5.0);

    expect($loaded)->toHaveCount(1)
        ->and($loaded[0]->url)->toBe('https://example.test/page')
        ->and($web->url())->toBe('https://example.test/page')
        ->and($web->title())->toBe('Hello')
        ->and($web->isLoading())->toBeFalse();
});

it('posts WebViewFailed with a reason for a file that is not there', function (): void {
    [, $web] = webViewWindow();
    $web->load('file:///nonexistent/x.html');

    $mail = mailUntil('WebViewFailed', 10.0);
    $failed = array_values(array_filter($mail, fn (object $m): bool => $m instanceof WebViewFailed));

    expect($failed)->toHaveCount(1)
        ->and($failed[0]->url)->toBe('file:///nonexistent/x.html')
        ->and($failed[0]->reason)->toBeString()->not->toBe('');
});

it('reloads and stops', function (): void {
    [, $web] = webViewWindow();
    $file = tempnam(sys_get_temp_dir(), 'web').'.html';
    file_put_contents($file, '<title>On disk</title><p>a</p>');
    $web->load('file://'.$file);
    mailUntil('WebViewLoaded', 10.0);
    pumpUntil(fn (): bool => $web->title() !== null, 5.0);
    $web->reload();
    $again = mailUntil('WebViewLoaded', 10.0);

    expect(mailNames($again))->toContain('WebViewLoaded');

    expect($web->title())->toBe('On disk');
    $web->stop();
    expect($web->isLoading())->toBeFalse();
    unlink($file);
});

it('posts nothing once removed', function (): void {
    [, $web] = webViewWindow();
    $web->loadHtml('<p>a</p>', 'https://example.test/a');
    $web->remove();

    pumpFor(1.0);

    expect(mailNames(takeMail()))->not->toContain('WebViewLoaded')->not->toContain('WebViewFailed');
});
