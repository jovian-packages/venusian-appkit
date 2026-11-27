<?php

namespace Jovian\Toolkits\Appkit\Video\Avkit;

use NSView;
use WeakReference;
use Jovian\Media\Avkit\AvkitPlayer;
use Jovian\Media\Avkit\AvkitPlayerListener;
use Jovian\Toolkits\Appkit\Video\AppkitVideoPlayer;
use Jovian\Toolkits\Appkit\Video\AppkitVideoListener;

/**
 * One AvkitPlayer for one video view: its AVPlayerView is the view, every call goes to it, and
 * what it reports goes to the view's listener. The listener is held weakly.
 */
final class AvkitVideoPlayer implements AppkitVideoPlayer, AvkitPlayerListener
{
    private AvkitPlayer $player;

    /** @var WeakReference<AppkitVideoListener> */
    private WeakReference $listener;

    public function __construct(AppkitVideoListener $listener)
    {
        $this->listener = WeakReference::create($listener);
        $this->player = new AvkitPlayer($this);
    }

    public function view(): NSView
    {
        return $this->player->view();
    }

    public function load(?string $url, bool $muted, bool $loop, bool $controls): void
    {
        $this->player->setMuted($muted);
        $this->player->setLoop($loop);
        $this->player->setControls($controls);
        $this->player->load($url);
    }

    public function play(): void
    {
        $this->player->play();
    }

    public function pause(): void
    {
        $this->player->pause();
    }

    public function seek(float $seconds): void
    {
        $this->player->seek($seconds);
    }

    public function setMuted(bool $muted): void
    {
        $this->player->setMuted($muted);
    }

    public function setLoop(bool $loop): void
    {
        $this->player->setLoop($loop);
    }

    public function setControls(bool $controls): void
    {
        $this->player->setControls($controls);
    }

    public function position(): float
    {
        return $this->player->position();
    }

    public function duration(): ?float
    {
        return $this->player->duration();
    }

    public function release(): void
    {
        $this->player->release();
    }

    public function avkitPlaying(): void
    {
        $this->listener->get()?->videoPlaying();
    }

    public function avkitPaused(): void
    {
        $this->listener->get()?->videoPaused();
    }

    public function avkitEnded(): void
    {
        $this->listener->get()?->videoEnded();
    }

    public function avkitFailed(?string $reason): void
    {
        $this->listener->get()?->videoFailed($reason);
    }
}
