<?php

namespace Jovian\Toolkits\Appkit\UI;

use Surface\Toolkits\UI\TKVideo;
use Jovian\Toolkits\Appkit\AppkitWindow;
use Jovian\Toolkits\Appkit\Video\AppkitVideoPlayer;
use Jovian\Toolkits\Appkit\Video\AppkitVideoListener;
use Jovian\Toolkits\Appkit\UI\Concerns\AppkitNative;
use Jovian\Toolkits\Appkit\UI\Concerns\TakesInputFromAbove;

/**
 * A video view. The player is made by the video manager's driver (appkit.video.driver): this
 * view places the player's view and hands it every call. What the player reports comes back
 * through AppkitVideoListener and is posted as Topside's video mail.
 *
 * A view that takes no input shows no controls: AppKit has no enabled state on a view, so the
 * player's transport is what a disabled video loses.
 */
class AppkitVideo extends TKVideo implements AppkitVideoListener
{
    use AppkitNative {
        releaseNative as private releaseView;
    }

    use TakesInputFromAbove;

    private ?AppkitVideoPlayer $player = null;

    protected function bootstrapNative(): void
    {
        /** @var AppkitWindow $window */
        $window = $this->window();
        $this->player = $window->adapter()->video()->driver()->player($this);
        $this->adoptNative($this->player->view());
    }

    protected function releaseNative(): void
    {
        $this->player->release();
        $this->player = null;
        $this->releaseView();
    }

    protected function applySource(?string $url): void
    {
        $this->player->load($url, $this->muted, $this->looping, $this->showsControls($this->takesInput()));
    }

    protected function applyPlay(): void
    {
        $this->player->play();
    }

    protected function applyPause(): void
    {
        $this->player->pause();
    }

    protected function applySeek(float $seconds): void
    {
        $this->player->seek($seconds);
    }

    protected function applyMuted(bool $muted): void
    {
        $this->player->setMuted($muted);
    }

    protected function applyLoop(bool $loop): void
    {
        $this->player->setLoop($loop);
    }

    protected function applyControls(bool $controls): void
    {
        $this->player->setControls($this->showsControls($this->takesInput()));
    }

    protected function applyNativeEnabled(bool $takes_input): void
    {
        $this->player?->setControls($this->showsControls($takes_input));
    }

    protected function readPosition(): float
    {
        return $this->player->position();
    }

    protected function readDuration(): ?float
    {
        return $this->player->duration();
    }

    public function videoPlaying(): void
    {
        $this->playing();
    }

    public function videoPaused(): void
    {
        $this->paused();
    }

    public function videoEnded(): void
    {
        $this->ended();
    }

    public function videoFailed(?string $reason): void
    {
        $this->failed($reason);
    }

    private function showsControls(bool $takes_input): bool
    {
        return $this->controls && $takes_input;
    }
}
