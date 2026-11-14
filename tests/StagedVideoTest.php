<?php

declare(strict_types=1);

use Surface\Contracts\Windows\Mail\View\VideoEnded;
use Surface\Contracts\Windows\Mail\View\VideoFailed;
use Surface\Contracts\Windows\Mail\View\VideoPaused;
use Surface\Contracts\Windows\Mail\View\VideoPlaying;
use Surface\Contracts\Windows\NativeVideo;
use Surface\Contracts\Windows\VideoSource;
use Surface\Contracts\Windows\WindowCapability;
use Surface\Contracts\Windows\WindowException;
use Windows\AppkitStagedVideo;
use Windows\AppkitStagedWindow;

/*
 * A staged window's native video on real AppKit: an AVPlayerView in a clipping view over the
 * view the window draws in. The clip is tests/fixtures/clip.mp4, as the toolkit VideoTest's.
 */

function videoStage(int $width = 320, int $height = 200): AppkitStagedWindow
{
    driver()->getStaged('stage')?->close();
    pumpFor(0.05);
    takeMail(session());

    return driver()->openStaged('stage', $width, $height);
}

/** Pump, collecting mail, until $done(mail so far) holds or $seconds pass. */
function stagedVideoMail(callable $done, float $seconds): array
{
    $until = microtime(true) + $seconds;
    $mail = [];
    while (microtime(true) < $until) {
        pumpFor(0.05);
        $mail = [...$mail, ...takeMail(session())];
        if ($done($mail)) {
            break;
        }
    }

    return $mail;
}

/** @return array{float, float, float, float} */
function rectOf(NSView $view): array
{
    $frame = $view->frame();

    return [$frame->x, $frame->y, $frame->width, $frame->height];
}

afterEach(function (): void {
    driver()->getStaged('stage')?->close();
    pumpFor(0.05);
    takeMail(session());
});

it('lists Video and makes an AVPlayerView with inline controls, off the window until placed', function (): void {
    $window = videoStage();
    $video = $window->video(VideoSource::Native, 'clip', __DIR__.'/fixtures/clip.mp4');

    expect($window->supports(WindowCapability::Video))->toBeTrue()
        ->and($video)->toBeInstanceOf(AppkitStagedVideo::class)
        ->and($video)->toBeInstanceOf(NativeVideo::class)
        ->and($video->native())->toBeInstanceOf(AVPlayerView::class)
        ->and($video->native()->controlsStyle())->toBe(AVPlayerViewControlsStyle::INLINE)
        ->and($video->native()->player())->not->toBeNull()
        ->and($video->isShown())->toBeFalse()
        ->and($video->clipView()->isHidden())->toBeTrue()
        ->and($video->clipView()->superview())->toBeNull()
        ->and($window->view()->subviews())->toBe([]);
});

it('places the player over the drawing view, top-left points turned into AppKit\'s', function (): void {
    $window = videoStage();
    $video = $window->video(VideoSource::Native, 'clip', __DIR__.'/fixtures/clip.mp4');
    $video->place(20.0, 30.0, 200.0, 100.0);
    $above = $window->view()->subviews();

    expect($video->isShown())->toBeTrue()
        ->and($video->clipView()->isHidden())->toBeFalse()
        ->and(end($above)->pointer())->toBe($video->clipView()->pointer())
        ->and($video->native()->superview()->pointer())->toBe($video->clipView()->pointer())
        ->and(rectOf($video->clipView()))->toBe([20.0, 70.0, 200.0, 100.0])
        ->and(rectOf($video->native()))->toBe([0.0, 0.0, 200.0, 100.0]);
});

it('cuts the player to its clip: the clip view is what shows, the player keeps its whole box inside it', function (): void {
    $window = videoStage();
    $video = $window->video(VideoSource::Native, 'clip', __DIR__.'/fixtures/clip.mp4');
    // Scrolled up 60 points under a region that starts at y 50: the top 20 points are cut off.
    $video->place(20.0, 30.0, 200.0, 100.0, [0.0, 50.0, 320.0, 150.0]);

    expect(rectOf($video->clipView()))->toBe([20.0, 70.0, 200.0, 80.0])
        ->and(rectOf($video->native()))->toBe([0.0, 0.0, 200.0, 100.0])
        ->and($video->clipView()->layer()?->masksToBounds())->toBeTrue();

    // And cut on the left and the bottom: the player sits at a negative offset inside the clip.
    $video->place(-40.0, 150.0, 200.0, 100.0, [0.0, 0.0, 320.0, 200.0]);
    expect(rectOf($video->clipView()))->toBe([0.0, 0.0, 160.0, 50.0])
        ->and(rectOf($video->native()))->toBe([-40.0, -50.0, 200.0, 100.0]);

    $video->place(20.0, -300.0, 200.0, 100.0, [0.0, 50.0, 320.0, 150.0]);
    expect($video->isShown())->toBeFalse()->and($video->clipView()->isHidden())->toBeTrue();
});

it('keeps its place from the top-left when the window resizes', function (): void {
    $window = videoStage();
    $video = $window->video(VideoSource::Native, 'clip', __DIR__.'/fixtures/clip.mp4');
    $video->place(20.0, 30.0, 200.0, 100.0);
    $window->resize(400, 300);

    expect(pumpUntil(fn (): bool => $window->size() === [400, 300], 3.0))->toBeTrue()
        ->and(rectOf($video->clipView()))->toBe([20.0, 170.0, 200.0, 100.0]);
});

it('goes back above a view put over the drawing view after it, as a surface lent later is', function (): void {
    $window = videoStage();
    $video = $window->video(VideoSource::Native, 'clip', __DIR__.'/fixtures/clip.mp4');
    $video->place(20.0, 30.0, 200.0, 100.0);
    $later = NSView::initWithFrame(new NSRect(0.0, 0.0, 320.0, 200.0));
    $window->view()->addSubview($later);
    $video->place(20.0, 40.0, 200.0, 100.0);
    $above = $window->view()->subviews();

    expect($above)->toHaveCount(2)
        ->and(end($above)->pointer())->toBe($video->clipView()->pointer());
});

it('plays to the end and reports it by name', function (): void {
    $window = videoStage();
    $video = $window->video(VideoSource::Native, 'clip', __DIR__.'/fixtures/clip.mp4')->setMuted(true);
    $video->place(20.0, 30.0, 200.0, 100.0);
    expect(pumpUntil(fn () => $window->isKey(), 5.0))->toBeTrue();
    takeMail(session());

    $video->play();
    $mail = stagedVideoMail(fn (array $m) => array_filter($m, fn ($x) => $x instanceof VideoEnded) !== [], 10.0);
    $video_mail = array_values(array_filter($mail, fn ($m) => $m instanceof VideoPlaying || $m instanceof VideoPaused || $m instanceof VideoEnded));

    expect(array_map(fn ($m) => $m::class, $video_mail))->toBe([VideoPlaying::class, VideoEnded::class])
        ->and($video_mail[0]->name())->toBe('view.video-playing.stage.clip')
        ->and($video_mail[0]->uuid())->toBe($video->uuid())
        ->and($video->duration())->toBeGreaterThan(0.9)
        ->and($video->isPlaying())->toBeFalse()
        ->and($video->native()->player()->isMuted())->toBeTrue();
});

it('reports a file that cannot play', function (): void {
    $window = videoStage();
    $video = $window->video(VideoSource::Native, 'clip', '/nope/clip.mp4');
    $video->play();
    $mail = stagedVideoMail(fn (array $m) => array_filter($m, fn ($x) => $x instanceof VideoFailed) !== [], 5.0);
    $failed = array_values(array_filter($mail, fn ($m) => $m instanceof VideoFailed));

    expect($failed)->not->toBe([])
        ->and($failed[0]->name())->toBe('view.video-failed.stage.clip')
        ->and($video->isPlaying())->toBeFalse();
});

it('takes its views off the window when removed, and with the window when it closes', function (): void {
    $window = videoStage();
    $video = $window->video(VideoSource::Native, 'clip', __DIR__.'/fixtures/clip.mp4');
    $video->place(20.0, 30.0, 200.0, 100.0);
    $video->remove();

    expect($video->clipView()->superview())->toBeNull()
        ->and($video->native()->player())->toBeNull()
        ->and($window->view()->subviews())->toBe([])
        ->and($window->videos())->toBe([])
        ->and(fn () => $video->place(0.0, 0.0, 1.0, 1.0))->toThrow(WindowException::class, "Video 'clip' was removed.");

    $again = $window->video(VideoSource::Native, 'clip', __DIR__.'/fixtures/clip.mp4');
    $again->place(20.0, 30.0, 200.0, 100.0);
    $window->close();

    expect($again->isRemoved())->toBeTrue()
        ->and($again->clipView()->superview())->toBeNull();
});
