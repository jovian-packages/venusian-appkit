<?php

namespace Jovian\Toolkits\Appkit;

use Closure;
use CFFileDescriptor;
use CFRunLoop;
use CFRunLoopSource;
use NSDate;
use NSEvent;
use NSEventMask;
use NSEventType;
use NSPoint;
use ObjCDelegate;
use NSApplication;
use NSApplicationActivationPolicy;
use Surface\Contracts\Toolkits\Toolkit;

class Appkit implements Toolkit
{
    /**
     * The loop's waiter descriptor as a run-loop source, while joined.
     * @var CFFileDescriptor|null
     */
    protected ?CFFileDescriptor $wake = null;
    /**
     * The application's delegate when it had none: a trampoline answering no selector, so AppKit
     * behaves as with no delegate while the slot is taken. SDL's video subsystem makes itself the
     * delegate (and the URL event handler) of an application that has none. AppKit holds it weakly.
     * @var ObjCDelegate|null
     */
    protected ?ObjCDelegate $delegate = null;

    /**
     * @var CFRunLoopSource|null
     */
    protected ?CFRunLoopSource $wake_source = null;

    /**
     * What keeps an event from AppKit, by owner: a filter that answers true for an event it handled.
     * @var array<int, Closure(NSEvent): bool>
     */
    protected array $withholders = [];

    /**
     * What runs after each event the pump took, once AppKit has finished with it, by owner.
     * @var array<int, Closure(): void>
     */
    protected array $after_dispatch = [];

    public function __construct(
        protected ?NSApplication $application = null
    )
    {
        $this->application ??= NSApplication::sharedApplication();
    }

    public function get(): NSApplication
    {
        return $this->application;
    }

    public function connect(): void
    {
        $this->application->setActivationPolicy(
            NSApplicationActivationPolicy::REGULAR
        );

        $this->application->activateIgnoringOtherApps(true);
    }

    public function disconnect(): void
    {
        $this->application->setActivationPolicy(
            NSApplicationActivationPolicy::PROHIBITED
        );
    }

    public function setDelegate(): static
    {
        if (is_null($this->application->delegate()))
        {
            // Under the NSObject protocol, which every process knows: it answers no selector, so it
            // needs none of NSApplicationDelegate's, and that one is unknown with only appkit loaded.
            $this->delegate = new ObjCDelegate('NSObject');
            $this->application->setDelegate($this->delegate);
        }

        return $this;
    }

    public function finishLaunching(): static
    {
        $this->application->finishLaunching();
        return $this;
    }

    public function pump(int $budget_ns): int
    {
        // The wake callout is one-shot: re-armed for each wait, so a descriptor the loop has not
        // read yet cannot keep posting while the queue is drained.
        $this->wake?->enableCallBacks(kCFFileDescriptorReadCallBack);

        $until = $budget_ns > 0 ? NSDate::dateWithTimeIntervalSinceNow($budget_ns / 1e9) : NSDate::distantPast();
        $dispatched = 0;

        while ($event = $this->application->nextEventMatchingMaskUntilDateInModeDequeue(NSEventMask::ANY, $until, NSDefaultRunLoopMode, true)) {
            $this->dispatch($event);
            $dispatched++;
            $until = NSDate::distantPast();
        }
        $this->application->updateWindows();

        return $dispatched;
    }

    public function wakeDescriptor(int $fd): void
    {
        $application = $this->application;

        $this->wake = CFFileDescriptor::create(null, $fd, false, function () use ($application): void {
            $application->postEventAtStart(NSEvent::otherEventWithTypeLocationModifierFlagsTimestampWindowNumberContextSubtypeData1Data2(
                NSEventType::APPLICATION_DEFINED, new NSPoint(0.0, 0.0), 0, 0.0, 0, null, 0, 0, 0,
            ), false);
        });
        $this->wake_source = $this->wake->createRunLoopSource(null, 0);
        CFRunLoop::getMain()->addSource($this->wake_source, kCFRunLoopCommonModes);
    }

    /**
     * Ask $filter about each event the pump takes, before AppKit gets it: true when it handled the
     * event. One for each owner; stopWithholding() ends it.
     *
     * @param Closure(NSEvent): bool $filter
     */
    public function withhold(object $owner, Closure $filter): void
    {
        $this->withholders[spl_object_id($owner)] = $filter;
    }

    public function stopWithholding(object $owner): void
    {
        unset($this->withholders[spl_object_id($owner)]);
    }

    /**
     * Dispatch one event the pump took: unless a filter withheld it, AppKit gets it; then what runs after each event runs.
     *
     * @param NSEvent $event
     * @return void
     */
    protected function dispatch(NSEvent $event): void
    {
        $withheld = false;
        foreach ($this->withholders as $filter) {
            if ($filter($event)) {
                $withheld = true;
                break;
            }
        }
        if (! $withheld) {
            $this->application->sendEvent($event);
        }
        foreach ($this->after_dispatch as $settle) {
            $settle();
        }
    }
}