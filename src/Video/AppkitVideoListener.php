<?php

namespace Jovian\Toolkits\Appkit\Video;

/**
 * What a player reports about its media.
 */
interface AppkitVideoListener
{
    /** The media started playing. */
    public function videoPlaying(): void;

    /** The media stopped short of its end. */
    public function videoPaused(): void;

    /** The media reached its end. */
    public function videoEnded(): void;

    /** The media cannot be played; $reason is the player's own text. */
    public function videoFailed(?string $reason): void;
}
