<?php

namespace Jovian\Toolkits\Appkit\Primitives;

use Jovian\Toolkits\Appkit\Primitives\Concerns\AppkitPrimitive;
use NSError;
use NSObject;
use NSRect;
use NSURL;
use NSURLRequest;
use ObjCDelegate;
use ObjCObserver;
use Surface\Contracts\Windows\Mail\View\WebViewFailed;
use Surface\Contracts\Windows\Mail\View\WebViewLoaded;
use Surface\Contracts\Windows\Primitives\Placement;
use Surface\Contracts\Windows\ToolkitWindow;
use Surface\Windows\Primitives\TKPrimitiveGroup;
use Surface\Windows\Primitives\TKWebView;
use WeakReference;
use WKWebView;
use WKWebViewConfiguration;

/**
 * A WKWebView. Its WKNavigationDelegate (an ObjCDelegate the primitive keeps: WebKit holds it
 * weakly) reports a navigation starting, finishing (WebViewLoaded) and failing before or after
 * it committed (WebViewFailed with the NSError's description). A navigation cancelled because
 * another one or stop() replaced it (NSURLErrorCancelled) is no failure and posts nothing.
 * The title is watched by key-value observing: WebKit can report it empty at the finish.
 */
class AppkitWebView extends TKWebView
{
    use AppkitPrimitive;

    /** NSURLErrorDomain's NSURLErrorCancelled. */
    private const int CANCELLED = -999;

    protected ObjCDelegate $navigation;

    /** Watches the view's title: WebKit can still report it empty when the navigation finishes. */
    protected ObjCObserver $title_observer;

    public function __construct(string $name, ToolkitWindow $window, ?TKPrimitiveGroup $parent, Placement $placement)
    {
        parent::__construct($name, $window, $parent, $placement);
        $view = $this->adoptNative(WKWebView::initWithFrameConfiguration(new NSRect(0.0, 0.0, 0.0, 0.0), WKWebViewConfiguration::init()));

        // The delegate holds its callables where PHP's collector cannot see them: a weak reference keeps it from holding the primitive.
        $self = WeakReference::create($this);
        $this->navigation = new ObjCDelegate('WKNavigationDelegate');
        $this->navigation->on('webView:didStartProvisionalNavigation:', static fn () => $self->get()?->report(true));
        $this->navigation->on('webView:didFinishNavigation:', static fn () => $self->get()?->finished());
        $this->navigation->on('webView:didFailProvisionalNavigation:withError:', static fn (WKWebView $view, ?NSObject $navigation, NSError $error) => $self->get()?->failed($error));
        $this->navigation->on('webView:didFailNavigation:withError:', static fn (WKWebView $view, ?NSObject $navigation, NSError $error) => $self->get()?->failed($error));
        $view->setNavigationDelegate($this->navigation);
        $this->title_observer = new ObjCObserver(static fn () => $self->get()?->report($self->get()->loading));
        $this->title_observer->observe($view, 'title', ObjCObserver::OPTION_NEW, 0);
    }

    public function native(): WKWebView
    {
        return $this->native;
    }

    protected function applyLoad(string $url): void
    {
        $this->native->loadRequest(NSURLRequest::requestWithURL(NSURL::URLWithString($url)));
    }

    protected function applyHtml(string $html, ?string $baseUrl): void
    {
        $this->native->loadHTMLStringBaseURL($html, is_null($baseUrl) ? null : NSURL::URLWithString($baseUrl));
    }

    protected function applyReload(): void
    {
        $this->native->reload();
    }

    protected function applyStop(): void
    {
        $this->native->stopLoading();
    }

    /** Record what the view reports now. */
    protected function report(bool $loading): void
    {
        if ($this->removed) {
            return;
        }
        $title = $this->native->title();
        $this->nativeLoadChanged($loading, $this->native->URL()?->absoluteString(), $title === '' ? null : $title);
    }

    protected function finished(): void
    {
        if ($this->removed) {
            return;
        }
        $this->report(false);
        $this->session()->post(new WebViewLoaded($this->window->name(), $this->path(), $this->uuid, $this->url));
    }

    protected function failed(NSError $error): void
    {
        if ($this->removed) {
            return;
        }
        $this->report($this->native->isLoading());
        if ($error->code() === self::CANCELLED && $error->domain() === 'NSURLErrorDomain') {
            return;
        }
        $this->session()->post(new WebViewFailed($this->window->name(), $this->path(), $this->uuid, $this->url, $error->localizedDescription()));
    }

    protected function releaseNative(): void
    {
        $this->native->stopLoading();
        $this->native->setNavigationDelegate(null);
        $this->title_observer->stop($this->native, 'title', 0);
    }
}
