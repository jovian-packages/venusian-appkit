<?php

namespace Jovian\Toolkits\Appkit\Primitives\Concerns;

use AVPlayer;
use AVPlayerActionAtItemEnd;
use AVPlayerItem;
use AVPlayerItemStatus;
use AVPlayerTimeControlStatus;
use AVPlayerView;
use NSNotificationCenter;
use NSObject;
use NSOperationQueue;
use NSURL;
use ObjCObserver;
use Surface\Contracts\Toolkits\Mail\Views\VideoEnded;
use Surface\Contracts\Toolkits\Mail\Views\VideoFailed;
use Surface\Contracts\Toolkits\Mail\Views\VideoPaused;
use Surface\Contracts\Toolkits\Mail\Views\VideoPlaying;
use WeakReference;

use function CMTimeGetSeconds;
use function CMTimeMake;
use function CMTimeMakeWithSeconds;

/**
 * One AVPlayer behind an AVPlayerView, for a toolkit window's TKVideo and a staged window's
 * NativeVideo alike: both abstracts keep the same state and ask for the same hooks, which
 * this answers. Each file is a new AVPlayerItem. The player's timeControlStatus reports
 * playing and paused; the item's status reports a file that cannot play (VideoFailed); its
 * end notification reports the end (VideoEnded). AVPlayer pauses itself at the end, and that
 * pause is the end, not a VideoPaused. Looping sets the end action to none, so the player
 * never stops and the end only seeks back. play() at the end starts again from the start. A
 * new file stops playback (no mail), as the other drivers' new stream starts paused.
 *
 * The class builds its AVPlayerView, then calls startPlayer() and applyFile(); it calls
 * stopPlayer() when its native goes, and posts through post().
 */
trait AppkitPlayer
{
    protected AVPlayer $player;

    protected ?AVPlayerItem $item = null;

    /**
     * The KVO context every registration of the observer is made and removed with.
     */
    protected const int KVO_CONTEXT = 0;

    /**
     * Hears the player's timeControlStatus and the item's status.
     */
    protected ObjCObserver $observer;

    /**
     * Token of the item's end-of-play observer.
     */
    protected ?NSObject $end_observer = null;

    abstract protected function post(object $mail): void;

    /** A player of its own for $view, nothing loaded. */
    protected function startPlayer(AVPlayerView $view): void
    {
        $this->player = AVPlayer::playerWithPlayerItem(null);
        $this->player->setActionAtItemEnd(AVPlayerActionAtItemEnd::PAUSE);
        // The observer holds its callable where PHP's collector cannot see it: a weak reference keeps it from holding the video.
        $self = WeakReference::create($this);
        $this->observer = new ObjCObserver(static fn (string $path, ?NSObject $object, array $change, int $context) => $path === 'status' ? $self->get()?->statusChanged() : $self->get()?->controlChanged());
        $this->observer->observe($this->player, 'timeControlStatus', ObjCObserver::OPTION_NEW, self::KVO_CONTEXT);
        $view->setPlayer($this->player);
    }

    /** Playback stopped, the item and the observers gone, $view without its player. */
    protected function stopPlayer(AVPlayerView $view): void
    {
        $this->player->pause();
        $this->forgetItem();
        $this->player->replaceCurrentItemWithPlayerItem(null);
        $this->observer->stop($this->player, 'timeControlStatus', self::KVO_CONTEXT);
        $view->setPlayer(null);
    }

    protected function applyFile(?string $file): void
    {
        // State first: the pause's own status change then finds nothing playing and posts nothing.
        $this->nativeStateChanged(false);
        $this->player->pause();
        $this->forgetItem();
        if (! is_null($file)) {
            $this->item = AVPlayerItem::playerItemWithURL(NSURL::fileURLWithPath($file));
            $this->observer->observe($this->item, 'status', ObjCObserver::OPTION_NEW, self::KVO_CONTEXT);
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
            $this->player->seekToTime(CMTimeMake(0, 600));
        }
        $this->player->play();
    }

    protected function applyPause(): void
    {
        $this->player->pause();
    }

    protected function applySeek(float $seconds): void
    {
        $this->player->seekToTime(CMTimeMakeWithSeconds($seconds, 600));
    }

    protected function applyMuted(bool $muted): void
    {
        $this->player->setMuted($muted);
    }

    protected function applyLoop(bool $loop): void
    {
        $this->player->setActionAtItemEnd($loop ? AVPlayerActionAtItemEnd::NONE : AVPlayerActionAtItemEnd::PAUSE);
    }

    protected function nativePosition(): float
    {
        $seconds = CMTimeGetSeconds($this->player->currentTime());

        return is_finite($seconds) ? $seconds : 0.0;
    }

    protected function nativeDuration(): ?float
    {
        if (is_null($this->item) || $this->item->status() !== AVPlayerItemStatus::READY_TO_PLAY) {
            return null;
        }
        $seconds = CMTimeGetSeconds($this->item->duration());

        return is_finite($seconds) ? $seconds : null;
    }

    protected function forgetItem(): void
    {
        if (is_null($this->item)) {
            return;
        }
        $this->observer->stop($this->item, 'status', self::KVO_CONTEXT);
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
            $this->player->seekToTime(CMTimeMake(0, 600));

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
}
