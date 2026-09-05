<?php

namespace Jovian\Venusian\AppKit\Views;

use Jovian\Bindings\AppKit\AV\AVPlayer;
use Jovian\Bindings\AppKit\AV\AVPlayerView;
use Jovian\Bindings\AppKit\NS\NSURL;
use Jovian\Bindings\AppKit\QuartzCore\CALayer;
use Jovian\Bindings\AppKit\Values\NSRect;
use Surface\Contracts\NativeWindows\Views\Color;
use Surface\Contracts\NativeWindows\WindowableException;
use Surface\NativeWindows\Views\Video;
use Surface\NativeWindows\Windowable;

/**
 * A Surface video over an AVPlayerView with inline native controls and an
 * AVPlayer minted per path. AVPlayerView is a plain NSView, not an
 * NSControl, so like the spinner it pays the top-left inversion itself;
 * a video has no natural size, so measure() answers the current frame.
 *
 * The AVPlayer box is held for the life of the view — the view retains
 * the player natively, but play()/pause()/setMuted() need the handle
 * again and a temp box would be dead by then.
 */
class AppKitVideo extends Video
{
    use ComposesAppKitStyle;

    protected ?AVPlayer $player = null;

    public function __construct(
        string $name,
        Windowable $window,
        ?string $path,
        public readonly AVPlayerView $player_view,
    ) {
        parent::__construct($name, $window, $path);
    }

    protected function applyPath(string $path): void
    {
        // Hold every box in a local until AVKit has retained natively —
        // never chain ->handle off a temp.
        $url = NSURL::fileURLWithPath($path);
        if (! $url instanceof NSURL) {
            throw new WindowableException("AppKit could not mint a URL for '{$path}'.");
        }

        $player = AVPlayer::playerWithURL($url->handle);
        if (! $player instanceof AVPlayer) {
            throw new WindowableException("AppKit could not mint a player for '{$path}'.");
        }

        $this->player = $player;
        $this->player_view->setPlayer($player->handle);

        // A fresh player forgets nothing Surface believes: re-assert mute.
        $player->setMuted($this->muted);
    }

    protected function applyPlaying(bool $playing): void
    {
        if (is_null($this->player)) {
            return;
        }

        if ($playing) {
            $this->player->play();
        } else {
            $this->player->pause();
        }
    }

    protected function applyMuted(bool $muted): void
    {
        $this->player?->setMuted($muted);
    }

    protected function applyVisible(bool $visible): void
    {
        $this->player_view->setHidden(! $visible);
    }

    protected function applyFrame(int $x, int $y, int $width, int $height): void
    {
        [, $content_height] = $this->layoutSpace();

        $this->player_view->setFrame(new NSRect(
            (float) $x,
            (float) ($content_height - $y - $height),
            (float) $width,
            (float) $height,
        ));
    }

    /**
     * A video has no natural size to measure — hug() keeps the frame.
     */
    protected function measure(): array
    {
        return [$this->width, $this->height];
    }

    protected function destroyNative(): void
    {
        $this->player?->pause();
        $this->player_view->removeFromSuperview();
    }

    protected function applyBackground(Color $color): void
    {
        $this->player_view->setWantsLayer(true);
        $layer = $this->player_view->layer();
        if ($layer instanceof CALayer) {
            // The NSColor must outlive the call: its CGColor is raw pointer
            // bits into the colour object, and the layer only CFRetains once
            // setBackgroundColor executes.
            $ns_color = $this->nsColor($color);
            $layer->setBackgroundColor($ns_color->CGColor());
        }
    }
}
