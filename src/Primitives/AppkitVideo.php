<?php

namespace Jovian\Toolkits\Appkit\Primitives;

use AVPlayer;
use AVPlayerActionAtItemEnd;
use AVPlayerItem;
use AVPlayerItemStatus;
use AVPlayerTimeControlStatus;
use AVPlayerView;
use AVPlayerViewControlsStyle;
use CMTime;
use Jovian\Toolkits\Appkit\Primitives\Concerns\AppkitPrimitive;
use NSNotificationCenter;
use NSObject;
use NSOperationQueue;
use NSRect;
use NSURL;
use ObjCObserver;
use Surface\Contracts\Windows\Mail\View\VideoEnded;
use Surface\Contracts\Windows\Mail\View\VideoFailed;
use Surface\Contracts\Windows\Mail\View\VideoPaused;
use Surface\Contracts\Windows\Mail\View\VideoPlaying;
use Surface\Contracts\Windows\Primitives\Placement;
use Surface\Contracts\Windows\ToolkitWindow;
use Surface\Windows\Primitives\TKPrimitiveGroup;
use Surface\Windows\Primitives\TKVideo;

/**
 * An AVPlayerView (inline controls) over one AVPlayer; each file is a new AVPlayerItem.
 * The player's timeControlStatus reports playing and paused; the item's status reports a
 * file that cannot play (VideoFailed); its end notification reports the end (VideoEnded).
 * AVPlayer pauses itself at the end, and that pause is the end, not a VideoPaused. Looping
 * sets the end action to none, so the player never stops and the end only seeks back.
 * play() at the end starts again from the start. A new file stops playback (no mail), as the
 * other drivers' new stream starts paused. Disabled, the view shows no controls.
 */
class AppkitVideo extends TKVideo
{
    use AppkitPrimitive;

    protected AVPlayer $player;

    protected ?AVPlayerItem $item = null;

    /**
     * Hears the player's timeControlStatus and the item's status.
     */
    protected ObjCObserver $observer;

    /**
     * Token of the item's end-of-play observer.
     */
    protected ?NSObject $end_observer = null;

    public function __construct(string $name, ToolkitWindow $window, ?TKPrimitiveGroup $parent, Placement $placement, ?string $file)
    {
        parent::__construct($name, $window, $parent, $placement, $file);
        $this->player = AVPlayer::playerWithPlayerItem(null);
        $this->player->setActionAtItemEnd(AVPlayerActionAtItemEnd::PAUSE);
        $this->observer = new ObjCObserver(fn (string $path) => $path === 'status' ? $this->statusChanged() : $this->controlChanged());
        $this->observer->observe($this->player, 'timeControlStatus', ObjCObserver::OPTION_NEW);

        $view = $this->adoptNative(AVPlayerView::initWithFrame(new NSRect()));
        $view->setControlsStyle(AVPlayerViewControlsStyle::INLINE);
        $view->setPlayer($this->player);
        $this->applyFile($file);
    }

    public function native(): AVPlayerView
    {
        return $this->native;
    }

    protected function applyFile(?string $file): void
    {
        // State first: the pause's own status change then finds nothing playing and posts nothing.
        $this->nativeStateChanged(false);
        $this->player->pause();
        $this->forgetItem();
        if (! is_null($file)) {
            $this->item = AVPlayerItem::playerItemWithURL(NSURL::fileURLWithPath($file));
            $this->observer->observe($this->item, 'status', ObjCObserver::OPTION_NEW);
            $this->end_observer = NSNotificationCenter::defaultCenter()->addObserverForNameObjectQueueUsingBlock(
                AVPlayerItemDidPlayToEndTimeNotification,
                $this->item,
                NSOperationQueue::mainQueue(),
                fn () => $this->ended(),
            );
        }
        $this->player->replaceCurrentItemWithPlayerItem($this->item);
    }

    protected function applyPlay(): void
    {
        if ($this->atEnd()) {
            $this->player->seekToTime(new CMTime(0, 600));
        }
        $this->player->play();
    }

    protected function applyPause(): void
    {
        $this->player->pause();
    }

    protected function applySeek(float $seconds): void
    {
        $this->player->seekToTime(CMTime::withSeconds($seconds, 600));
    }

    protected function applyMuted(bool $muted): void
    {
        $this->player->setMuted($muted);
    }

    protected function applyLoop(bool $loop): void
    {
        $this->player->setActionAtItemEnd($loop ? AVPlayerActionAtItemEnd::NONE : AVPlayerActionAtItemEnd::PAUSE);
    }

    protected function applyNativeEnabled(bool $on): void
    {
        $this->native->setControlsStyle($on ? AVPlayerViewControlsStyle::INLINE : AVPlayerViewControlsStyle::NONE);
    }

    protected function nativePosition(): float
    {
        $seconds = $this->player->currentTime()->seconds();

        return is_finite($seconds) ? $seconds : 0.0;
    }

    protected function nativeDuration(): ?float
    {
        if (is_null($this->item) || $this->item->status() !== AVPlayerItemStatus::READY_TO_PLAY) {
            return null;
        }
        $seconds = $this->item->duration()->seconds();

        return is_finite($seconds) ? $seconds : null;
    }

    protected function releaseNative(): void
    {
        $this->player->pause();
        $this->forgetItem();
        $this->player->replaceCurrentItemWithPlayerItem(null);
        $this->observer->stop($this->player, 'timeControlStatus');
        $this->native->setPlayer(null);
    }

    protected function forgetItem(): void
    {
        if (is_null($this->item)) {
            return;
        }
        $this->observer->stop($this->item, 'status');
        NSNotificationCenter::defaultCenter()->removeObserver($this->end_observer);
        $this->end_observer = null;
        $this->item = null;
    }

    protected function controlChanged(): void
    {
        if ($this->removed) {
            return;
        }

        $status = $this->player->timeControlStatus();
        if ($status === AVPlayerTimeControlStatus::PLAYING && ! $this->playing) {
            $this->nativeStateChanged(true);
            $this->post(new VideoPlaying($this->window->name(), $this->path(), $this->uuid));
        } elseif ($status === AVPlayerTimeControlStatus::PAUSED && $this->playing && ! $this->atEnd()) {
            $this->nativeStateChanged(false);
            $this->post(new VideoPaused($this->window->name(), $this->path(), $this->uuid));
        }
    }

    protected function statusChanged(): void
    {
        if ($this->removed || is_null($this->item) || $this->item->status() !== AVPlayerItemStatus::FAILED) {
            return;
        }

        $this->nativeStateChanged(false);
        $reason = $this->item->error()?->description() ?? 'The media cannot be played.';
        $this->post(new VideoFailed($this->window->name(), $this->path(), $this->uuid, $reason));
    }

    protected function ended(): void
    {
        if ($this->removed) {
            return;
        }

        if ($this->looping) {
            $this->player->seekToTime(new CMTime(0, 600));

            return;
        }

        $this->nativeStateChanged(false);
        $this->post(new VideoEnded($this->window->name(), $this->path(), $this->uuid));
    }

    /**
     * Whether the player stands at the end of its item: the pause AVPlayer makes there is the end.
     * @return bool
     */
    protected function atEnd(): bool
    {
        $duration = $this->nativeDuration();

        return ! is_null($duration) && $this->nativePosition() >= $duration - 0.05;
    }

    protected function post(object $mail): void
    {
        $this->session()->post($mail);
    }
}
