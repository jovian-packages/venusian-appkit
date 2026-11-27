<?php

declare(strict_types=1);

use Jovian\Toolkits\Appkit\Video\AppkitVideoDriver;
use Jovian\Toolkits\Appkit\Video\AppkitVideoPlayer;
use Jovian\Toolkits\Appkit\Video\AppkitVideoListener;
use Jovian\Toolkits\Appkit\Video\AppkitVideoManager;
use Jovian\Toolkits\Appkit\Video\Avkit\AvkitVideoDriver;
use Jovian\Toolkits\Appkit\Video\Avkit\AvkitVideoPlayer;
use Jovian\Toolkits\Appkit\Video\WebView\WebViewVideoDriver;
use Jovian\Toolkits\Appkit\Video\WebView\WebViewVideoPlayer;
use Voyager\Config\Repository;
use Voyager\Core\RenderedInstance;

/*
| The video manager: which driver a video's player comes from. Nothing is put on screen.
*/

/** A manager on a core whose appkit.video.driver is $driver. */
function videoManager(string $driver): AppkitVideoManager
{
    $app = new RenderedInstance(sys_get_temp_dir());
    $app->registerInstance('config', new Repository(['appkit' => ['video' => ['driver' => $driver]]]));
    $manager = new AppkitVideoManager($app);
    toolkitApp();

    return $manager;
}

it('makes the webview driver by default, once', function (): void {
    $manager = driver()->video();

    expect($manager->driver())->toBeInstanceOf(WebViewVideoDriver::class)
        ->and($manager->driver())->toBe($manager->driver('webview'))
        ->and(driver()->video())->toBe($manager);
});

it('takes the default from appkit.video.driver', function (): void {
    $manager = videoManager('elsewhere');
    $driver = new class implements AppkitVideoDriver {
        public function player(AppkitVideoListener $listener): AppkitVideoPlayer
        {
            throw new LogicException('not used');
        }
    };
    $manager->extend('elsewhere', fn () => $driver);

    expect($manager->driver())->toBe($driver);
});

it('refuses a driver it does not know', function (): void {
    expect(fn () => videoManager('nothing')->driver())->toThrow(InvalidArgumentException::class, 'Driver [nothing] not supported.');
});

it('gives the webview driver a WebKit player', function (): void {
    $listener = new class implements AppkitVideoListener {
        public function videoPlaying(): void {}

        public function videoPaused(): void {}

        public function videoEnded(): void {}

        public function videoFailed(?string $reason): void {}
    };
    $player = driver()->video()->driver('webview')->player($listener);

    expect($player)->toBeInstanceOf(WebViewVideoPlayer::class)
        ->and($player->view())->toBeInstanceOf(WKWebView::class)
        ->and($player->position())->toBe(0.0)
        ->and($player->duration())->toBeNull();
    $player->release();
});

it('gives the avkit driver an AVPlayer in an AVPlayerView', function (): void {
    $listener = new class implements AppkitVideoListener {
        public function videoPlaying(): void {}

        public function videoPaused(): void {}

        public function videoEnded(): void {}

        public function videoFailed(?string $reason): void {}
    };
    $driver = driver()->video()->driver('avkit');
    $player = $driver->player($listener);

    expect($driver)->toBeInstanceOf(AvkitVideoDriver::class)
        ->and($player)->toBeInstanceOf(AvkitVideoPlayer::class)
        ->and($player->view())->toBeInstanceOf(AVPlayerView::class)
        ->and($player->view()->player())->toBeInstanceOf(AVPlayer::class)
        ->and($player->duration())->toBeNull();
    $player->release();
});

it('refuses the avkit driver without jovian/venusian-avkit, naming the install', function (): void {
    // Another PHP, whose autoloader no longer finds jovian/venusian-avkit.
    $code = 'require '.var_export(dirname(__DIR__, 2).'/vendor/autoload.php', true).';'.<<<'PHP'
        foreach (spl_autoload_functions() as $loader) {
            if (is_array($loader) && $loader[0] instanceof Composer\Autoload\ClassLoader) {
                $loader[0]->setPsr4('Jovian\\Media\\Avkit\\', []);
            }
        }
        try {
            new Jovian\Toolkits\Appkit\Video\Avkit\AvkitVideoDriver();
            echo 'made';
        } catch (RuntimeException $e) {
            echo $e->getMessage();
        }
        PHP;
    $said = shell_exec(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($code));

    expect($said)->toBe("The 'avkit' video driver needs jovian/venusian-avkit. Install it with 'composer require jovian/venusian-avkit'");
});
