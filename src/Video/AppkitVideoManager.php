<?php

namespace Jovian\Toolkits\Appkit\Video;

use Jovian\Toolkits\Appkit\Video\Avkit\AvkitVideoDriver;
use Jovian\Toolkits\Appkit\Video\WebView\WebViewVideoDriver;
use Voyager\NutsAndBolts\Manager;

/**
 * The players this package's videos are made with: one driver per name, made the first time
 * it is asked for. The default is appkit.video.driver. 'webview' is built in; 'avkit' plays
 * through jovian/venusian-avkit when that package is installed; another package or the app
 * adds a driver with extend().
 */
class AppkitVideoManager extends Manager
{
    public function driver(?string $driver = null): AppkitVideoDriver
    {
        return parent::driver($driver);
    }

    public function createWebviewDriver(): WebViewVideoDriver
    {
        return new WebViewVideoDriver();
    }

    public function createAvkitDriver(): AvkitVideoDriver
    {
        return new AvkitVideoDriver();
    }

    public function getDefaultDriver(): ?string
    {
        return $this->config->get('appkit.video.driver');
    }
}
