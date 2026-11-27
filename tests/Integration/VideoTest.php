<?php

declare(strict_types=1);

use Jovian\Toolkits\Appkit\UI\AppkitVideo;
use Jovian\Toolkits\Appkit\Video\Avkit\AvkitVideoPlayer;
use Jovian\Toolkits\Appkit\Video\WebView\WebViewVideoPlayer;
use Surface\Contracts\Toolkits\Mail\Views\VideoEnded;
use Surface\Contracts\Toolkits\Mail\Views\VideoFailed;
use Surface\Contracts\Toolkits\Mail\Views\VideoPlaying;

/*
| A video on each driver: 'webview' (a WKWebView showing the media as its document) and
| 'avkit' (jovian/venusian-avkit's AVPlayer in an AVPlayerView). Every case runs on both. The
| fixtures are a 1.2 s 160 x 120 H.264 clip and a 1 s AAC tone, played from disk and from PHP's
| built-in server on 127.0.0.1 with byte ranges (fixtures/serve.php).
*/

dataset('video drivers', ['webview', 'avkit']);

afterEach(function (): void {
    closeAll();
    toolkitApp()->make('config')->set('appkit.video.driver', 'webview');
});

/** A window with one video filling its content column, its player from $driver. */
function appkitVideo(string $driver): AppkitVideo
{
    toolkitApp()->make('config')->set('appkit.video.driver', $driver);
    $window = openWindow('video', 400, 300);
    /** @var AppkitVideo $video */
    $video = $window->column('root')->video('player')->fill(true, true);
    pumpFor(0.05);
    takeMail();

    return $video;
}

function videoFixture(string $name): string
{
    return dirname(__DIR__).'/fixtures/'.$name;
}

/** The fixture's address on a server started once for the process. */
function servedFixture(string $name): string
{
    static $port = null;
    if (is_null($port)) {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);
        $dir = dirname(__DIR__).'/fixtures';
        $server = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", "{$dir}/serve.php"], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $dir);
        register_shutdown_function(static fn () => proc_terminate($server));
        // Until it answers: a refused connection while it starts is expected, not a warning.
        set_error_handler(static fn (): bool => true);
        $until = microtime(true) + 5.0;
        while (! stream_socket_client("tcp://127.0.0.1:{$port}", $code, $text, 0.1) && microtime(true) < $until) {
            usleep(20_000);
        }
        restore_error_handler();
    }

    return "http://127.0.0.1:{$port}/{$name}";
}

/** @param list<object> $mail @return list<class-string> the video mail, in order */
function videoMail(array $mail): array
{
    return array_values(array_map(fn (object $m): string => $m::class, array_filter($mail, fn (object $m): bool => str_starts_with($m::class, 'Surface\Contracts\Toolkits\Mail\Views\Video'))));
}

/**
 * What the player holds now, read from the native player: muted, loop, controls, paused, and
 * the position in seconds.
 */
function playerReads(AppkitVideo $video, string $what): mixed
{
    $native = $video->native();
    if ($native instanceof AVPlayerView) {
        $player = $native->player();

        return match ($what) {
            'muted' => $player->isMuted(),
            'loop' => $player->actionAtItemEnd() === AVPlayerActionAtItemEnd::NONE,
            'controls' => $native->controlsStyle() !== AVPlayerViewControlsStyle::NONE,
            'paused' => $player->timeControlStatus() === AVPlayerTimeControlStatus::PAUSED,
            'position' => CMTimeGetSeconds($player->currentTime()),
        };
    }

    $expression = ['muted' => 'muted', 'loop' => 'loop', 'controls' => 'controls', 'paused' => 'paused', 'position' => 'currentTime'][$what];
    $result = null;
    $done = false;
    $native->evaluateJavaScriptCompletionHandler("window.__venusianVideo ? window.__venusianVideo.v.{$expression} : null", function (mixed $value) use (&$result, &$done): void {
        [$result, $done] = [$value, true];
    });
    pumpUntil(function () use (&$done): bool {
        return $done;
    }, 5.0);

    return $result;
}

function untilLoaded(AppkitVideo $video): void
{
    expect(pumpUntil(fn (): bool => ! is_null($video->duration()), 10.0))->toBeTrue();
}

it('is the view of its driver\'s player', function (string $driver): void {
    $video = appkitVideo($driver);
    $player = (fn () => $this->player)->call($video);

    expect($player)->toBeInstanceOf(['webview' => WebViewVideoPlayer::class, 'avkit' => AvkitVideoPlayer::class][$driver])
        ->and($video->native())->toBeInstanceOf(['webview' => WKWebView::class, 'avkit' => AVPlayerView::class][$driver])
        ->and($video->native())->toBe($player->view())
        ->and($video->supported())->toBeTrue()
        ->and($video->position())->toBe(0.0)
        ->and($video->duration())->toBeNull();
})->with('video drivers');

it('plays a file to the end: VideoPlaying, then VideoEnded', function (string $driver, string $source, float $length): void {
    $video = appkitVideo($driver);
    $video->setSource(str_starts_with($source, 'http:') ? servedFixture(substr($source, 5)) : videoFixture($source));
    untilLoaded($video);
    $video->play();
    $mail = mailUntil('VideoEnded', 10.0);

    expect(videoMail($mail))->toBe([VideoPlaying::class, VideoEnded::class])
        ->and($video->duration())->toEqualWithDelta($length, 0.1)
        ->and($video->isPlaying())->toBeFalse();
})->with('video drivers')->with([
    'a file' => ['clip.mp4', 1.2],
    'an audio file' => ['tone.m4a', 1.0],
    'a clip over HTTP' => ['http:clip.mp4', 1.2],
    'audio over HTTP' => ['http:tone.m4a', 1.0],
]);

it('plays a file on a volume of its own', function (string $driver): void {
    $dir = sys_get_temp_dir().'/venusian-appkit-volume-'.getmypid();
    $image = "{$dir}.sparseimage";
    @mkdir($dir);
    exec('hdiutil create -quiet -size 8m -fs APFS -type SPARSE -volname VenusianVideo '.escapeshellarg($image).' 2>&1', $out, $made);
    exec('hdiutil attach -quiet -nobrowse -mountpoint '.escapeshellarg($dir).' '.escapeshellarg($image).' 2>&1', $out, $attached);
    if ($made !== 0 || $attached !== 0) {
        test()->markTestSkipped('hdiutil could not make a volume: '.implode(' ', $out));
    }

    try {
        expect(stat($dir)['dev'])->not->toBe(stat(sys_get_temp_dir())['dev']);
        copy(videoFixture('clip.mp4'), "{$dir}/clip.mp4");
        $video = appkitVideo($driver);
        $video->setSource("{$dir}/clip.mp4");
        untilLoaded($video);
        $video->play();

        expect(videoMail(mailUntil('VideoEnded', 10.0)))->toBe([VideoPlaying::class, VideoEnded::class]);
        $video->remove();
        pumpFor(0.2);
    } finally {
        exec('hdiutil detach -quiet -force '.escapeshellarg($dir));
        @unlink($image);
        @rmdir($dir);
    }
})->with('video drivers');

it('stays paused at its start until play()', function (string $driver): void {
    $video = appkitVideo($driver);
    $video->setSource(videoFixture('clip.mp4'));
    untilLoaded($video);
    pumpFor(0.5);

    expect(videoMail(takeMail()))->toBe([])
        ->and($video->position())->toBe(0.0)
        ->and(playerReads($video, 'paused'))->toBeTrue();
})->with('video drivers');

it('posts VideoPaused on pause, and seeks to the time asked for', function (string $driver): void {
    $video = appkitVideo($driver);
    $video->setSource(videoFixture('tone.m4a'));
    untilLoaded($video);
    $video->setMuted(true)->play();
    mailUntil('VideoPlaying', 5.0);
    $video->pause();
    $paused = mailUntil('VideoPaused', 5.0);
    $video->seek(0.5);
    pumpFor(0.3);

    expect(countMail($paused, 'VideoPaused', 'video'))->toBe(1)
        ->and($video->isPlaying())->toBeFalse()
        ->and($video->position())->toEqualWithDelta(0.5, 0.01)
        ->and(playerReads($video, 'position'))->toEqualWithDelta(0.5, 0.01);
})->with('video drivers');

it('hands muting, looping and controls to the player, set before the source and after it', function (string $driver): void {
    $video = appkitVideo($driver);
    $video->setMuted(true)->setLoop(true)->setControls(true);
    $video->setSource(videoFixture('clip.mp4'));
    untilLoaded($video);
    $before = [playerReads($video, 'muted'), playerReads($video, 'loop'), playerReads($video, 'controls')];
    $video->setMuted(false)->setLoop(false)->setControls(false);
    pumpFor(0.1);
    $after = [playerReads($video, 'muted'), playerReads($video, 'loop'), playerReads($video, 'controls')];

    expect($before)->toBe([true, true, true])
        ->and($after)->toBe([false, false, false]);
})->with('video drivers');

it('loops with no VideoEnded', function (string $driver): void {
    $video = appkitVideo($driver);
    $video->setMuted(true)->setLoop(true)->setSource(videoFixture('clip.mp4'));
    untilLoaded($video);
    $video->play();
    $mail = mailUntil('VideoEnded', 3.0);

    expect(videoMail($mail))->toBe([VideoPlaying::class])
        ->and($video->isPlaying())->toBeTrue();
})->with('video drivers');

it('shows no controls while it takes no input', function (string $driver): void {
    $video = appkitVideo($driver);
    $video->setControls(true)->setSource(videoFixture('clip.mp4'));
    untilLoaded($video);
    $video->parent()->setEnabled(false);
    pumpFor(0.1);
    $disabled = playerReads($video, 'controls');
    $video->parent()->setEnabled(true);
    pumpFor(0.1);

    expect([$disabled, playerReads($video, 'controls'), $video->hasControls()])->toBe([false, true, true]);
})->with('video drivers');

it('posts VideoFailed with a reason', function (string $driver, Closure $source): void {
    $video = appkitVideo($driver);
    $video->setSource($source());
    $failed = array_values(array_filter(mailUntil('VideoFailed', 10.0), fn (object $m): bool => $m instanceof VideoFailed));
    pumpFor(0.3);
    $more = array_filter(takeMail(), fn (object $m): bool => $m instanceof VideoFailed);

    expect($failed)->toHaveCount(1)
        ->and($more)->toBe([])
        ->and($failed[0]->reason)->toBeString()->not->toBe('');
})->with('video drivers')->with([
    'a file that is not there' => fn () => '/nonexistent/appkit-video-test.mp4',
    'a file that is not media' => fn () => videoFixture('serve.php'),
    'a server that refuses' => fn () => 'http://127.0.0.1:9/clip.mp4',
    'a page that is not there' => fn () => servedFixture('nothing.mp4'),
]);

it('posts nothing for a source it moved on from', function (string $driver): void {
    $video = appkitVideo($driver);
    $video->setMuted(true)->setSource(videoFixture('clip.mp4'));
    untilLoaded($video);
    $video->play();
    mailUntil('VideoPlaying', 5.0);
    $video->setSource(videoFixture('tone.m4a'));
    pumpFor(2.0);

    expect(videoMail(takeMail()))->toBe([])
        ->and($video->duration())->toEqualWithDelta(1.0, 0.1);
})->with('video drivers');

it('posts nothing once removed', function (string $driver): void {
    $video = appkitVideo($driver);
    $video->setMuted(true)->setSource(videoFixture('clip.mp4'));
    $video->play();
    $video->remove();
    pumpFor(1.5);

    expect(videoMail(takeMail()))->toBe([]);
})->with('video drivers');
