<?php

namespace Jovian\Toolkits\Appkit\Video\WebView;

use RuntimeException;
use Jovian\Toolkits\Appkit\Video\AppkitVideoDriver;
use Jovian\Toolkits\Appkit\Video\AppkitVideoPlayer;
use Jovian\Toolkits\Appkit\Video\AppkitVideoListener;

/**
 * The 'webview' video driver: each player is a WKWebView showing the media as its document.
 */
class WebViewVideoDriver implements AppkitVideoDriver
{
    /** The WebKit classes a player is made of. */
    private const array NEEDS = ['WKWebView', 'WKWebViewConfiguration', 'WKUserContentController', 'WKUserScript', 'WKScriptMessage'];

    /**
     * @throws RuntimeException When the loaded ext-appkit lacks one of the WebKit classes.
     */
    public function __construct()
    {
        $missing = array_values(array_filter(self::NEEDS, static fn (string $class): bool => ! class_exists($class)));
        if ($missing !== []) {
            throw new RuntimeException("The 'webview' video driver needs ext-appkit 0.11.2 or later, which has ".implode(', ', $missing).". Install it with 'pie install php-io-extensions/appkit'");
        }
    }

    public function player(AppkitVideoListener $listener): AppkitVideoPlayer
    {
        return new WebViewVideoPlayer($listener);
    }
}
