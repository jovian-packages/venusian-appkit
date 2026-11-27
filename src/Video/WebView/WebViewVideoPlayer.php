<?php

namespace Jovian\Toolkits\Appkit\Video\WebView;

use NSURL;
use NSRect;
use NSView;
use NSError;
use WKWebView;
use WKUserScript;
use ObjCDelegate;
use NSURLRequest;
use WeakReference;
use WKScriptMessage;
use WKUserContentController;
use WKWebViewConfiguration;
use WKUserScriptInjectionTime;
use Jovian\Toolkits\Appkit\Video\AppkitVideoPlayer;
use Jovian\Toolkits\Appkit\Video\AppkitVideoListener;

/**
 * A WKWebView showing the media as its document: WebKit makes a media file (video or audio)
 * a page that holds one <video> element. A local file loads with loadFileURL: and read access
 * to that file alone. With the media as the page, WebKit's two rules for local files hold on
 * every volume: a page reads only what loadFileURL: grants, and a file page shows a file only
 * on its own volume. A URL of any other scheme loads as a request.
 *
 * A user script at document start takes the element as WebKit adds it: autoplay off, paused,
 * the muting, looping and controls of the load, the picture whole on black. On a media
 * document the script finds the element twice, so it takes it once. A second script at
 * document end reports a page with no element (an error page, a file that is not media).
 *
 * The element's events come back through one script message handler as JSON: playing, pause,
 * ended and error go to the listener, and every report carries the position and the duration,
 * which position() and duration() read. WebKit sends pause before ended at the end, with the
 * position at the duration: that pause is dropped. Each load has its own number, and a report
 * from an earlier load is dropped. Calls made before the script has the element wait for it.
 *
 * Buffering is WebKit's: play() starts the media when WebKit has enough of it.
 *
 * WebKit holds the message handler strongly and the navigation delegate weakly; both are kept
 * here, and their closures hold this player weakly.
 */
final class WebViewVideoPlayer implements AppkitVideoPlayer
{
    /** The name the page posts to: window.webkit.messageHandlers.venusianVideo. */
    private const string HANDLER = 'venusianVideo';

    /** WebKit's end of a media document's navigation: WebKitErrorDomain 204, "Plug-in handled load". */
    private const int PLUGIN_HANDLED_LOAD = 204;

    /** NSURLErrorCancelled: another load or release() replaced the navigation. */
    private const int CANCELLED = -999;

    private WKWebView $web;

    private WKUserContentController $scripts;

    private ObjCDelegate $handler;

    private ObjCDelegate $navigation;

    /** @var WeakReference<AppkitVideoListener> */
    private WeakReference $listener;

    /** The current load's number; reports carry it. */
    private int $load = 0;

    /** The script has the element: calls reach it. */
    private bool $ready = false;

    /** @var list<string> calls waiting for the element */
    private array $waiting = [];

    private float $at = 0.0;

    private ?float $length = null;

    private bool $released = false;

    public function __construct(AppkitVideoListener $listener)
    {
        $this->listener = WeakReference::create($listener);

        $configuration = WKWebViewConfiguration::init();
        $this->scripts = $configuration->userContentController();

        $self = WeakReference::create($this);
        $this->handler = new ObjCDelegate('WKScriptMessageHandler');
        $this->handler->on('userContentController:didReceiveScriptMessage:', static function ($controller, WKScriptMessage $message) use ($self): void {
            $self->get()?->heard($message->body());
        });
        $this->scripts->addScriptMessageHandlerName($this->handler, self::HANDLER);

        $this->web = WKWebView::initWithFrameConfiguration(new NSRect(0.0, 0.0, 0.0, 0.0), $configuration);

        $failed = static function ($web, $navigation, ?NSError $error = null) use ($self): void {
            $self->get()?->navigationFailed($error);
        };
        $this->navigation = new ObjCDelegate('WKNavigationDelegate');
        $this->navigation->on('webView:didFailNavigation:withError:', $failed);
        $this->navigation->on('webView:didFailProvisionalNavigation:withError:', $failed);
        $this->web->setNavigationDelegate($this->navigation);
    }

    public function view(): NSView
    {
        return $this->web;
    }

    public function load(?string $url, bool $muted, bool $loop, bool $controls): void
    {
        $this->load++;
        $this->ready = false;
        $this->waiting = [];
        $this->at = 0.0;
        $this->length = null;
        $this->scripts->removeAllUserScripts();

        if (is_null($url)) {
            $this->web->loadHTMLStringBaseURL('', null);

            return;
        }

        $target = NSURL::URLWithString($url);
        if (is_null($target)) {
            $this->web->loadHTMLStringBaseURL('', null);
            $this->listener->get()?->videoFailed("WebKit cannot read '{$url}' as a URL.");

            return;
        }

        $this->scripts->addUserScript(WKUserScript::initWithSourceInjectionTimeForMainFrameOnly($this->takeScript($muted, $loop, $controls), WKUserScriptInjectionTime::AT_DOCUMENT_START, true));
        $this->scripts->addUserScript(WKUserScript::initWithSourceInjectionTimeForMainFrameOnly($this->checkScript(), WKUserScriptInjectionTime::AT_DOCUMENT_END, true));

        if (str_starts_with(strtolower($url), 'file:')) {
            $this->web->loadFileURLAllowingReadAccessToURL($target, $target);
        } else {
            $this->web->loadRequest(NSURLRequest::requestWithURL($target));
        }
    }

    public function play(): void
    {
        // A play() that a pause() interrupts rejects with AbortError: that is not a failure.
        $this->tell('p.v.play().catch(function(e){if(e.name!=="AbortError"){p.say("error",e.message||String(e));}});');
    }

    public function pause(): void
    {
        $this->tell('p.v.pause();');
    }

    public function seek(float $seconds): void
    {
        $this->at = $seconds;
        $this->tell('p.v.currentTime='.json_encode($seconds).';');
    }

    public function setMuted(bool $muted): void
    {
        $this->tell('p.v.muted='.json_encode($muted).';');
    }

    public function setLoop(bool $loop): void
    {
        $this->tell('p.v.loop='.json_encode($loop).';');
    }

    public function setControls(bool $controls): void
    {
        $this->tell('p.v.controls='.json_encode($controls).';');
    }

    public function position(): float
    {
        return $this->at;
    }

    public function duration(): ?float
    {
        return $this->length;
    }

    /**
     * The handler and the scripts go first, so the page reports nothing more; then the
     * delegate, the load, and the page.
     */
    public function release(): void
    {
        if ($this->released) {
            return;
        }

        $this->released = true;
        $this->waiting = [];
        $this->scripts->removeScriptMessageHandlerForName(self::HANDLER);
        $this->scripts->removeAllUserScripts();
        $this->web->setNavigationDelegate(null);
        $this->web->stopLoading();
        $this->web->loadHTMLStringBaseURL('', null);
    }

    /**
     * One report from the page: {"load": n, "event": ..., "position": s, "duration": s|null,
     * "error": ...}. One from an earlier load, or not from this page's script, is dropped.
     */
    private function heard(mixed $body): void
    {
        $report = is_string($body) ? json_decode($body, true) : null;
        if ($this->released || ! is_array($report) || ($report['load'] ?? null) !== $this->load || ! is_string($report['event'] ?? null)) {
            return;
        }

        $position = $report['position'] ?? null;
        if (is_int($position) || is_float($position)) {
            $this->at = max(0.0, (float) $position);
        }
        $duration = $report['duration'] ?? null;
        $this->length = (is_int($duration) || is_float($duration)) && $duration > 0 ? (float) $duration : null;

        $listener = $this->listener->get();
        match ($report['event']) {
            'ready' => $this->took(),
            'playing' => $listener?->videoPlaying(),
            'pause' => $listener?->videoPaused(),
            'ended' => $listener?->videoEnded(),
            'error' => $listener?->videoFailed(is_string($report['error'] ?? null) && $report['error'] !== '' ? $report['error'] : null),
            default => null,
        };
    }

    /** The script has the element: what waited runs now. */
    private function took(): void
    {
        $this->ready = true;
        [$calls, $this->waiting] = [$this->waiting, []];
        foreach ($calls as $call) {
            $this->run($call);
        }
    }

    private function navigationFailed(?NSError $error): void
    {
        if ($this->released || is_null($error)) {
            return;
        }

        if (($error->domain() === 'NSURLErrorDomain' && $error->code() === self::CANCELLED)
            || ($error->domain() === 'WebKitErrorDomain' && $error->code() === self::PLUGIN_HANDLED_LOAD)) {
            return;
        }

        $this->listener->get()?->videoFailed($error->localizedDescription());
    }

    /** Run $call on the element now, or once the script has it. */
    private function tell(string $call): void
    {
        if ($this->released) {
            return;
        }

        if ($this->ready) {
            $this->run($call);
        } else {
            $this->waiting[] = $call;
        }
    }

    private function run(string $call): void
    {
        $this->web->evaluateJavaScriptCompletionHandler('(function(p){if(p){'.$call.'}})(window.__venusianVideo);', null);
    }

    /**
     * The document-start script: takes the element once, applies the load's settings, styles
     * the page, reports the element's events, and posts "ready".
     */
    private function takeScript(bool $muted, bool $loop, bool $controls): string
    {
        return '(function(){var load='.$this->load.',h=window.webkit.messageHandlers.'.self::HANDLER.';'
            .'function take(v){if(v.__venusian){return;}v.__venusian=true;'
            .'function say(e,x){var d=v.duration;h.postMessage(JSON.stringify({load:load,event:e,position:v.currentTime,duration:isFinite(d)?d:null,error:x||null}));}'
            .'v.autoplay=false;v.pause();v.muted='.json_encode($muted).';v.loop='.json_encode($loop).';v.controls='.json_encode($controls).';'
            .'v.style.cssText="position:fixed;left:0;top:0;width:100%;height:100%;max-width:none;max-height:none;margin:0;object-fit:contain;background:#000";'
            .'document.documentElement.style.background="#000";if(document.body){document.body.style.margin="0";document.body.style.background="#000";}'
            .'["playing","ended","loadedmetadata","durationchange","timeupdate","seeked"].forEach(function(e){v.addEventListener(e,function(){say(e);});});'
            .'v.addEventListener("pause",function(){if(!v.ended&&!(v.currentTime>=v.duration)){say("pause");}});'
            .'v.addEventListener("error",function(){var m=v.error;say("error",m?(m.message||("Media error "+m.code+".")):null);});'
            .'window.__venusianVideo={v:v,say:say};say("ready");}'
            .'var found=document.querySelector("video");if(found){take(found);return;}'
            .'new MutationObserver(function(l,o){var v=document.querySelector("video");if(v){o.disconnect();take(v);}}).observe(document,{childList:true,subtree:true});})();';
    }

    /**
     * The document-end script: a page with no element is a source WebKit does not play as
     * media.
     */
    private function checkScript(): string
    {
        return '(function(){if(!document.querySelector("video")){window.webkit.messageHandlers.'.self::HANDLER.'.postMessage(JSON.stringify({load:'.$this->load.',event:"error",position:0,duration:null,'
            .'error:"WebKit shows this source as "+(document.contentType||"a page")+", not as audio or video."}));}})();';
    }
}
