<?php

namespace Jovian\Toolkits\Appkit\Video;

/**
 * Makes the players of one kind. A driver checks what it needs when it is made, and throws
 * with the fix when something is missing.
 */
interface AppkitVideoDriver
{
    /**
     * A player for one video view, nothing loaded, reporting to $listener.
     */
    public function player(AppkitVideoListener $listener): AppkitVideoPlayer;
}
