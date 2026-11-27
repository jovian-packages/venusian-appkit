<?php

namespace Jovian\Toolkits\Appkit\Video\Avkit;

use RuntimeException;
use Jovian\Media\Avkit\AvkitPlayer;
use Jovian\Toolkits\Appkit\Video\AppkitVideoDriver;
use Jovian\Toolkits\Appkit\Video\AppkitVideoPlayer;
use Jovian\Toolkits\Appkit\Video\AppkitVideoListener;

/**
 * The 'avkit' video driver: each player is jovian/venusian-avkit's AvkitPlayer, an AVPlayer
 * in an AVPlayerView. The package is a suggestion of this one, checked when the driver is made.
 */
class AvkitVideoDriver implements AppkitVideoDriver
{
    /**
     * @throws RuntimeException When jovian/venusian-avkit or ext-avkit is missing.
     */
    public function __construct()
    {
        if (! extension_loaded('avkit')) {
            throw new RuntimeException("The 'avkit' video driver needs ext-avkit. Install it with 'pie install php-io-extensions/avkit'");
        }

        if (! class_exists(AvkitPlayer::class)) {
            throw new RuntimeException("The 'avkit' video driver needs jovian/venusian-avkit. Install it with 'composer require jovian/venusian-avkit'");
        }
    }

    public function player(AppkitVideoListener $listener): AppkitVideoPlayer
    {
        return new AvkitVideoPlayer($listener);
    }
}
