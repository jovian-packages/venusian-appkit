<?php

declare(strict_types=1);

use Surface\Contracts\Windows\Mail\View\VideoEnded;
use Surface\Contracts\Windows\Mail\View\VideoFailed;
use Surface\Contracts\Windows\Mail\View\VideoPaused;
use Surface\Contracts\Windows\Mail\View\VideoPlaying;

beforeEach(function (): void {
    driver()->closeAll();
    pumpFor(0.05);
    takeMail(session());
});

afterEach(function (): void {
    driver()->closeAll();
    pumpFor(0.05);
    takeMail(session());
});

/** Pump, collecting mail, until $done(mail so far) holds or $seconds pass. */
function mailUntil(callable $done, float $seconds): array
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

it('plays a clip to the end and reports a missing file', function (): void {
    $window = driver()->open('main', 200, 200);
    $video = $window->column('m')->video('v', __DIR__.'/../fixtures/clip.mp4');
    $window->present();
    $video->play();
    $until = microtime(true) + 10.0;
    $mail = [];
    while (microtime(true) < $until) { pumpFor(0.05); $mail = [...$mail, ...takeMail(session())]; if (array_filter($mail, fn ($m) => $m instanceof VideoEnded)) break; }

    expect(array_map(fn ($m) => $m::class, $mail))->toContain(VideoPlaying::class, VideoEnded::class)
        ->and($video->duration())->toBeGreaterThan(0.9);
    $video->setFile('/nope/clip.mp4');
    $video->play();
    $until = microtime(true) + 5.0; $mail = [];
    while (microtime(true) < $until) { pumpFor(0.05); $mail = [...$mail, ...takeMail(session())]; if (array_filter($mail, fn ($m) => $m instanceof VideoFailed)) break; }
    expect(array_filter($mail, fn ($m) => $m instanceof VideoFailed))->not->toBe([]);
});

it('reports play, pause and the end in order, with no pause at the end', function (): void {
    $window = driver()->open('main', 200, 200);
    $video = $window->column('m')->video('v', __DIR__.'/../fixtures/clip.mp4')->setMuted(true);
    $window->present();
    expect(pumpUntil(fn () => $window->isKey(), 5.0))->toBeTrue();
    takeMail(session());

    expect($video->native())->toBeInstanceOf(AVPlayerView::class)
        ->and($video->native()->controlsStyle())->toBe(AVPlayerViewControlsStyle::INLINE)
        ->and($video->native()->player()->isMuted())->toBeTrue()
        ->and($video->isPlaying())->toBeFalse();

    $video->play();
    $started = mailUntil(fn ($m) => $video->isPlaying(), 10.0);
    expect($video->isPlaying())->toBeTrue()
        ->and(array_map(fn ($m) => $m::class, $started))->toBe([VideoPlaying::class]);
    $video->pause();
    $paused = mailUntil(fn ($m) => ! $video->isPlaying(), 10.0);
    expect(array_map(fn ($m) => $m::class, $paused))->toBe([VideoPaused::class]);

    $video->seek(0.5);
    $video->play();
    $rest = mailUntil(fn ($m) => array_filter($m, fn ($x) => $x instanceof VideoEnded) !== [], 10.0);
    $kinds = array_map(fn ($m) => $m::class, $rest);
    expect($kinds)->toBe([VideoPlaying::class, VideoEnded::class])
        ->and($video->isPlaying())->toBeFalse()
        ->and(round($video->position(), 1))->toBe(1.0)
        ->and($rest[1]->path())->toBe('m.v');
});

it('loops without ending and reports no duration until the media loads', function (): void {
    $window = driver()->open('main', 200, 200);
    $video = $window->column('m')->video('v')->setMuted(true)->setLoop(true);

    expect($video->duration())->toBeNull()
        ->and($video->position())->toBe(0.0);

    $video->setFile(__DIR__.'/../fixtures/clip.mp4');
    $window->present();
    $video->play();
    expect(pumpUntil(fn () => $video->isPlaying(), 10.0))->toBeTrue();
    takeMail(session());
    // Past the end of the 1 s clip twice over: a loop restarts instead of ending.
    $mail = mailUntil(fn () => false, 2.5);

    expect(array_filter($mail, fn ($m) => $m instanceof VideoEnded))->toBe([])
        ->and(array_filter($mail, fn ($m) => $m instanceof VideoPaused))->toBe([])
        ->and(array_filter($mail, fn ($m) => $m instanceof VideoPlaying))->toBe([])
        ->and($video->native()->player()->actionAtItemEnd())->toBe(AVPlayerActionAtItemEnd::NONE)
        ->and($video->isPlaying())->toBeTrue();
    $video->setLoop(false);
    expect($video->native()->player()->actionAtItemEnd())->toBe(AVPlayerActionAtItemEnd::PAUSE);
    $video->setFile(null);
    expect($video->native()->player()->currentItem())->toBeNull();
});

it('stops reporting once removed', function (): void {
    $window = driver()->open('main', 200, 200);
    $main = $window->column('m');
    $video = $main->video('v', __DIR__.'/../fixtures/clip.mp4')->setMuted(true);
    $window->present();
    $video->play();
    expect(pumpUntil(fn () => $video->isPlaying(), 10.0))->toBeTrue();
    takeMail(session());
    $player = $video->native()->player();
    $video->remove();
    $mail = mailUntil(fn () => false, 1.5);

    expect($mail)->toBe([])
        ->and(pumpUntil(fn () => $player->timeControlStatus() === AVPlayerTimeControlStatus::PAUSED, 5.0))->toBeTrue()
        ->and($player->currentItem())->toBeNull();
});

it('replays from the start when played after the end', function (): void {
    $window = driver()->open('main', 200, 200);
    $video = $window->column('m')->video('v', __DIR__.'/../fixtures/clip.mp4')->setMuted(true);
    $window->present();
    $video->play();
    mailUntil(fn ($m) => array_filter($m, fn ($x) => $x instanceof VideoEnded) !== [], 10.0);

    $video->play();
    $positions = [];
    $again = mailUntil(function ($m) use ($video, &$positions) {
        $positions[] = $video->position();

        return array_filter($m, fn ($x) => $x instanceof VideoEnded) !== [];
    }, 10.0);

    expect(array_map(fn ($m) => $m::class, $again))->toBe([VideoPlaying::class, VideoEnded::class])
        ->and(min($positions))->toBeLessThan(0.9);
});

it('stops playing when its file changes, and posts nothing for it', function (): void {
    $window = driver()->open('main', 200, 200);
    $video = $window->column('m')->video('v', __DIR__.'/../fixtures/clip.mp4')->setMuted(true);
    $window->present();
    $video->play();
    expect(pumpUntil(fn () => $video->isPlaying(), 10.0))->toBeTrue();
    takeMail(session());

    $video->setFile(__DIR__.'/../fixtures/clip.mp4');
    $mail = mailUntil(fn () => false, 0.5);
    expect($video->isPlaying())->toBeFalse()
        ->and($video->native()->player()->rate())->toBe(0.0)
        ->and($mail)->toBe([]);

    $video->play();
    expect(pumpUntil(fn () => $video->isPlaying(), 10.0))->toBeTrue();
    $video->setFile(null);
    expect($video->isPlaying())->toBeFalse();
});

it('hides its controls while disabled, itself or through a container', function (): void {
    $window = driver()->open('main', 200, 200);
    $main = $window->column('m');
    $video = $main->video('v', __DIR__.'/../fixtures/clip.mp4');

    $video->disable();
    expect($video->native()->controlsStyle())->toBe(AVPlayerViewControlsStyle::NONE);
    $video->enable();
    expect($video->native()->controlsStyle())->toBe(AVPlayerViewControlsStyle::INLINE);
    $main->disable();
    expect($video->native()->controlsStyle())->toBe(AVPlayerViewControlsStyle::NONE);
});
