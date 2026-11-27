<?php

namespace Jovian\Toolkits\Appkit\Video;

use NSView;

/**
 * One video view's player. It shows the media in its view, takes the calls the view is
 * given, and reports what the media did to its listener.
 */
interface AppkitVideoPlayer
{
    /**
     * The view the media shows in, placed in the layout like any native.
     */
    public function view(): NSView;

    /**
     * Load the media at $url (null: unload), paused at its start, with these settings. What
     * the old media reports after this is dropped.
     */
    public function load(?string $url, bool $muted, bool $loop, bool $controls): void;

    public function play(): void;

    public function pause(): void;

    /**
     * @param float $seconds from the start, 0 or more
     */
    public function seek(float $seconds): void;

    public function setMuted(bool $muted): void;

    public function setLoop(bool $loop): void;

    /**
     * Whether the player shows its own transport.
     */
    public function setControls(bool $controls): void;

    /**
     * Seconds from the start; 0 with nothing loaded.
     */
    public function position(): float;

    /**
     * Seconds; null until the media reports it.
     */
    public function duration(): ?float;

    /**
     * Stop, and report nothing more. The player is not used after this.
     */
    public function release(): void;
}
