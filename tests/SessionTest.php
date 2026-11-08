<?php

declare(strict_types=1);

use Surface\Bridge\ToolkitPump;
use Surface\Contracts\HumanInput\InputTap;
use Voyager\IOPools\EventLoop;
use Voyager\IOPools\LoopWaiter;
use Voyager\IOPools\PromiseEngines\GuzzlePromiseEngine;
use Voyager\IOPools\ResourceRegistry;
use Voyager\IOPools\Resources\WakeSource;
use Voyager\IOPools\Waiter\KqueueWaiterBackend;
use Voyager\IOPools\Waiter\Wakes\Readable;

it('connects as a regular app and disconnects to prohibited', function (): void {
    $session = session();

    expect($session->connected())->toBeTrue()
        ->and($session->application()->activationPolicy())->toBe(NSApplicationActivationPolicy::REGULAR);

    $session->disconnect();
    expect($session->application()->activationPolicy())->toBe(NSApplicationActivationPolicy::PROHIBITED);

    $session->connect();
    expect($session->application()->activationPolicy())->toBe(NSApplicationActivationPolicy::REGULAR);
});

it('waits at most the budget when nothing arrives', function (): void {
    session()->pump(0);

    // Names whatever wakes the wait early, so a stray event from an earlier window shows in the failure.
    $seen = [];
    $tap = new class($seen) implements InputTap {
        public function __construct(private array &$seen) {}

        public function see(object $native_event): void
        {
            $this->seen[] = $native_event instanceof NSEvent ? $native_event->type()->name.' window '.$native_event->windowNumber() : $native_event::class;
        }
    };
    session()->tap($tap);
    $t = hrtime(true);
    session()->pump(30_000_000);
    $ms = (hrtime(true) - $t) / 1e6;
    session()->untap($tap);

    expect($ms)->toBeGreaterThanOrEqual(25.0, sprintf('woken after %.1f ms by: %s', $ms, implode(', ', $seen) ?: 'nothing seen'))->toBeLessThan(120.0);
});

it('joins a loop: the loop waiter ends the toolkit sleep the moment a watched stream turns readable', function (): void {
    $registry = new ResourceRegistry();
    $loop = new EventLoop($registry, new LoopWaiter($registry, new KqueueWaiterBackend(), 16_000_000), new GuzzlePromiseEngine());
    [$read, $write] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

    $loop->resource('reader', new class($read) extends WakeSource {
        public function __construct(private $stream) {}

        public function wakes(): array { return [new Readable($this->stream)]; }

        public function woke(array $fired): void { fread($this->stream, 64); }
    });

    $session = session();
    $session->joinLoop($loop);

    try {
        expect($registry->sleeper())->toBeInstanceOf(ToolkitPump::class);

        // One loop turn so the waiter registers the stream with kqueue, then a sleep a write ends early.
        // until() checks its condition before the first turn and again at the loop head.
        $checks = 0;
        $loop->until(function () use (&$checks): bool {
            return ++$checks > 2;
        });
        $proc = proc_open([PHP_BINARY, '-n', '-r', 'usleep(100000); echo hrtime(true);'], [1 => $write], $pipes);

        $started = hrtime(true);
        $session->pump(2_000_000_000);
        $returned = hrtime(true);
        proc_close($proc);
        stream_set_blocking($read, false);
        $written = (int) fread($read, 64);

        // hrtime is the system's monotonic clock in both processes: the wait outlasted the write
        // and ended within 100 ms of it, however long the child took to start.
        expect($written)->toBeGreaterThan($started)
            ->and($returned)->toBeGreaterThanOrEqual($written)
            ->and(($returned - $written) / 1e6)->toBeLessThan(100.0);
    } finally {
        $session->leaveLoop();
    }

    expect($registry->sleeper())->toBeNull();
});
