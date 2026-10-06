<?php

namespace Jovian\Toolkits\Appkit\Bridge;

use CFFileDescriptor;
use CFRunLoop;
use CFRunLoopSource;
use Jovian\Toolkits\Appkit\Primitives\AppkitCanvas;
use NSApplication;
use NSApplicationActivationPolicy;
use NSDate;
use NSEvent;
use NSEventMask;
use NSEventType;
use NSPoint;
use ObjCDelegate;
use Surface\Bridge\BridgedToolkitSession;

class AppkitSession extends BridgedToolkitSession
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
     * Take hold of the shared NSApplication and finish launching it: the part that cannot be repeated.
     * @return void
     */
    protected function initializeEngine(): void
    {
        $this->application = NSApplication::sharedApplication();
        if (is_null($this->application->delegate())) {
            $this->delegate = new ObjCDelegate('NSApplicationDelegate');
            $this->application->setDelegate($this->delegate);
        }
        $this->application->finishLaunching();
    }

    /**
     * Become a regular app and take focus, which is what raises the Dock icon.
     *
     * activateIgnoringOtherApps:, not activate: a process started from a terminal is never the
     * frontmost app, and macOS declines activate's cooperative request for it, which leaves
     * every window unable to become key.
     * @return void
     */
    protected function connectToEngine(): void
    {
        $this->application->setActivationPolicy(NSApplicationActivationPolicy::REGULAR);
        $this->application->activateIgnoringOtherApps(true);
    }

    /**
     * Drop out of the Dock and the app switcher, leaving the app running headless.
     * @return void
     */
    protected function disconnectEngine(): void
    {
        $this->application->setActivationPolicy(NSApplicationActivationPolicy::PROHIBITED);
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
        AppkitCanvas::sweepSdlWindows();

        return $dispatched;
    }

    /**
     * The descriptor becomes a run-loop source whose callout posts an application-defined event:
     * that is what ends nextEventMatchingMask's wait.
     *
     * @param int $fd
     * @return void
     */
    protected function wakeDescriptor(int $fd): void
    {
        $application = $this->application;

        $this->wake = CFFileDescriptor::create($fd, false, function () use ($application): void {
            $application->postEventAtStart(NSEvent::otherEventWithTypeLocationModifierFlagsTimestampWindowNumberContextSubtypeData1Data2(
                NSEventType::APPLICATION_DEFINED, new NSPoint(), 0, 0.0, 0, null, 0, 0, 0,
            ), false);
        });
        $this->wake_source = $this->wake->createRunLoopSource(0);
        CFRunLoop::getMain()->addSource($this->wake_source, kCFRunLoopCommonModes);
    }

    /**
     * @return void
     */
    protected function releaseWakeDescriptor(): void
    {
        if (! is_null($this->wake_source)) {
            CFRunLoop::getMain()->removeSource($this->wake_source, kCFRunLoopCommonModes);
        }
        $this->wake?->invalidate();
        $this->wake = null;
        $this->wake_source = null;
    }

    /**
     * The shared application, for window hosts.
     * @return NSApplication
     */
    public function application(): NSApplication
    {
        return $this->application;
    }

    /**
     * The standard About panel, non-modal, carrying the identity given.
     *
     * @param array{name?: string|null, version?: string|null, copyright?: string|null} $about
     * @return void
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
}
