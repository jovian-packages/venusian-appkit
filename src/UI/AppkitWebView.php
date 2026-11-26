<?php

namespace Jovian\Toolkits\Appkit\UI;

use NSURL;
use NSRect;
use NSError;
use WeakReference;
use WKWebView;
use ObjCDelegate;
use NSURLRequest;
use WKWebViewConfiguration;
use Surface\Toolkits\UI\TKWebView;
use Jovian\Toolkits\Appkit\UI\Concerns\AppkitNative;
use Jovian\Toolkits\Appkit\UI\Concerns\TakesInputFromAbove;

/**
 * A `WKWebView`. WebKit holds the page: url(), title() and isLoading() read the view live.
 *
 * A load's end comes through a `WKNavigationDelegate` (an `ObjCDelegate`): finishing posts
 * WebViewLoaded, a failed navigation or a failed provisional navigation (a URL that cannot be
 * reached, a file that is not there) posts WebViewFailed with the error's localised
 * description. WebKit holds a delegate weakly, so the primitive keeps it; the delegate's
 * closures hold the primitive weakly, and releaseNative() clears the delegate first, so a
 * removed view posts nothing.
 */
class AppkitWebView extends TKWebView
{
    use AppkitNative {
        releaseNative as private releaseView;
    }

    use TakesInputFromAbove;

    private ?ObjCDelegate $navigation = null;

    /** The last URL asked for: a provisional failure leaves the view's own URL empty. */
    private ?string $requested = null;

    protected function bootstrapNative(): void
    {
        $web = WKWebView::initWithFrameConfiguration(new NSRect(0.0, 0.0, 0.0, 0.0), WKWebViewConfiguration::init());

        $self = WeakReference::create($this);
        $this->navigation = new ObjCDelegate('WKNavigationDelegate');
        $this->navigation->on('webView:didFinishNavigation:', static function () use ($self): void {
            $view = $self->get();
            $view?->loaded($view->url() ?? $view->requested);
        });
        $failed = static function ($web, $navigation, ?NSError $error = null) use ($self): void {
            $view = $self->get();
            $view?->failed($view->url() ?? $view->requested, $error?->localizedDescription());
        };
        $this->navigation->on('webView:didFailNavigation:withError:', $failed);
        $this->navigation->on('webView:didFailProvisionalNavigation:withError:', $failed);
        $web->setNavigationDelegate($this->navigation);

        $this->adoptNative($web);
    }

    protected function applyLoad(string $url): void
    {
        $this->requested = $url;
        $this->webNative()->loadRequest(NSURLRequest::requestWithURL(NSURL::URLWithString($url)));
    }

    protected function applyLoadHtml(string $html, ?string $baseUrl): void
    {
        $this->requested = $baseUrl;
        $this->webNative()->loadHTMLStringBaseURL($html, is_null($baseUrl) ? null : NSURL::URLWithString($baseUrl));
    }

    protected function applyReload(): void
    {
        $this->webNative()->reload();
    }

    protected function applyStop(): void
    {
        $this->webNative()->stopLoading();
    }

    public function url(): ?string
    {
        return $this->webNative()->URL()?->absoluteString();
    }

    public function title(): ?string
    {
        $title = $this->webNative()->title();

        return $title === '' ? null : $title;
    }

    public function isLoading(): bool
    {
        return $this->webNative()->isLoading();
    }

    /** A WKWebView has no enabled state of its own. */
    protected function applyNativeEnabled(bool $takes_input): void {}

    protected function releaseNative(): void
    {
        $web = $this->webNative();
        $web->setNavigationDelegate(null);
        $this->navigation = null;
        $web->stopLoading();
        $this->releaseView();
    }

    private function webNative(): WKWebView
    {
        /** @var WKWebView */
        return $this->native();
    }
}
