<?php

namespace Jovian\Toolkits\Appkit;

use NSDate;
use NSEvent;
use NSPoint;
use CFRunLoop;
use NSEventType;
use NSEventMask;
use ObjCDelegate;
use NSApplication;
use CFFileDescriptor;
use CFRunLoopSource;
use NSApplicationActivationPolicy;
use Surface\Toolkits\ToolkitSession;


class AppkitSession extends ToolkitSession
{
    /**
     * The shared application, from initialization until the process ends.
     * @var NSApplication|null
     */
    protected ?NSApplication $application = null;

    /**
     * The application's delegate when it had none: a trampoline answering no selector, so AppKit
     * behaves as with no delegate while the slot is taken. SDL's video subsystem makes itself the
     * delegate (and the URL event handler) of an application that has none. AppKit holds it weakly.
     * @var ObjCDelegate|null
     */
    protected ?ObjCDelegate $delegate = null;

    /**
     * The loop's waiter descriptor as a run-loop source, while joined.
     * @var CFFileDescriptor|null
     */
    protected ?CFFileDescriptor $wake = null;

    /**
     * @var CFRunLoopSource|null
     */
    protected ?CFRunLoopSource $wake_source = null;



    /**
     * The shared application, for window hosts.
     * @return NSApplication
     */
    public function application(): NSApplication
    {
        return $this->application;
    }

    /**
     * Take hold of the shared NSApplication and finish launching it: the part that cannot be repeated.
     * @return void
     */
    protected function initializeEngine(): void
    {
        $this->application = NSApplication::sharedApplication();
        $this->setDelegate();
        $this->application->finishLaunching();
    }

    protected function connectToEngine(): void
    {
        $this->application->setActivationPolicy(
            NSApplicationActivationPolicy::REGULAR
        );

        $this->application->activateIgnoringOtherApps(true);
    }

    /**
     * Drop out of the Dock and the app switcher, leaving the app running headless.
     */
    protected function disconnectEngine(): void
    {
        $this->application->setActivationPolicy(NSApplicationActivationPolicy::PROHIBITED);
    }

    /**
     * Show the standard About panel. A missing or empty field keeps AppKit's own value.
     * @param array{name?: string|null, version?: string|null, copyright?: string|null} $about
     */
    public function showAbout(array $about): void
    {
        $options = [];
        if (! empty($about['name'])) {
            $options['ApplicationName'] = (string) $about['name'];
        }
        if (! empty($about['version'])) {
            $options['ApplicationVersion'] = (string) $about['version'];
            $options['Version'] = (string) $about['version'];
        }
        if (! empty($about['copyright'])) {
            $options['Copyright'] = (string) $about['copyright'];
        }

        $this->application->orderFrontStandardAboutPanelWithOptions($options);
    }

    /**
     * Wait at most $budget_ns for the next event, then dispatch it and everything queued behind it.
     *
     * @param int $budget_ns Zero dispatches what is queued without waiting.
     * @return int Events dispatched.
     */
    public function pump(int $budget_ns): int
    {
        // The wake callout is one-shot: re-armed for each wait, so a descriptor the loop has not
        // read yet cannot keep posting while the queue is drained.
        $this->wake?->enableCallBacks(kCFFileDescriptorReadCallBack);

        $until = $budget_ns > 0 ? NSDate::dateWithTimeIntervalSinceNow($budget_ns / 1e9) : NSDate::distantPast();
        $dispatched = 0;

        while ($event = $this->application->nextEventMatchingMaskUntilDateInModeDequeue(NSEventMask::ANY, $until, NSDefaultRunLoopMode, true)) {
            $this->application->sendEvent($event);
            $dispatched++;
            $until = NSDate::distantPast();
        }
        $this->application->updateWindows();

        return $dispatched;
    }

    /**
     * The descriptor becomes a run-loop source whose callout posts an application-defined event:
     * that is what ends nextEventMatchingMask's wait.
     */
    protected function wakeDescriptor(int $fd): void
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

    protected function releaseWakeDescriptor(): void
    {
        if (! is_null($this->wake_source)) {
            CFRunLoop::getMain()->removeSource($this->wake_source, kCFRunLoopCommonModes);
        }
        $this->wake?->invalidate();
        $this->wake = null;
        $this->wake_source = null;
    }

    private function setDelegate(): void
    {
        if (is_null($this->application->delegate()))
        {
            $this->delegate = new ObjCDelegate('NSApplicationDelegate');
            $this->application->setDelegate($this->delegate);
        }
    }


}