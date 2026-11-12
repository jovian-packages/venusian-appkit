<?php

namespace Jovian\Toolkits\Appkit\Windows;

use Jovian\Toolkits\Appkit\Bridge\AppkitSession;
use NSEvent;
use NSEventModifierFlags;
use NSEventType;
use NSPoint;
use NSView;
use Surface\Contracts\HumanInput\InputTap;
use Surface\Contracts\Windows\Mail\View\GroupClicked;
use Surface\Contracts\Windows\Primitives\TKPrimitive;
use Surface\Windows\Primitives\TKPrimitiveGroup;
use WeakReference;

/**
 * A toolkit window's clicks on clickable groups as mail, from the events the session pumps (a tap on
 * the session, as AppkitRightClicks): a primary press, without Control, and its release on the same
 * group (TKPrimitiveGroup::clickTarget). Nothing is withheld: the views see their events as before.
 * A control's press runs AppKit's tracking loop, which takes the release; it clicks no group anyway.
 */
final class AppkitGroupClicks implements InputTap
{
    private bool $watching = true;

    /** @var WeakReference<AppkitWindow> the window holds this */
    private readonly WeakReference $window;

    /** The uuid of the group the primary press landed on, until its release. */
    private ?string $pressed = null;

    public function __construct(
        AppkitWindow $window,
        private readonly AppkitSession $session,
    ) {
        $this->window = WeakReference::create($window);
        $session->tap($this);
    }

    public function watching(): bool
    {
        return $this->watching;
    }

    /** The window closed: stop seeing the session's events. */
    public function detach(): void
    {
        $this->session->untap($this);
        $this->watching = false;
    }

    public function see(object $native_event): void
    {
        $window = $this->window->get();
        if (is_null($window) || ! $window->isOpen()) {
            $this->detach();

            return;
        }
        if (! $native_event instanceof NSEvent || $native_event->windowNumber() !== $window->native()->windowNumber() || $window->hasSheet()) {
            return;
        }
        $type = $native_event->type();
        if ($type === NSEventType::LEFT_MOUSE_DOWN) {
            $control = ($native_event->modifierFlags() & NSEventModifierFlags::CONTROL->value) !== 0;
            $target = $control ? null : $this->targetAt($window, $native_event->locationInWindow());
            $this->pressed = is_null($target) ? null : $target[0]->uuid();
        } elseif ($type === NSEventType::LEFT_MOUSE_UP && ! is_null($this->pressed)) {
            $pressed = $this->pressed;
            $this->pressed = null;
            $point = $native_event->locationInWindow();
            $target = $this->targetAt($window, $point);
            if (! is_null($target) && $target[0]->uuid() === $pressed) {
                [$x, $y] = AppkitRightClicks::topLeft($target[1], $point);
                $this->session->post(new GroupClicked($window->name(), $target[0]->path(), $pressed, $x, $y));
            }
        }
    }

    /**
     * The clickable group a window (base) point clicks, and its view.
     *
     * @return array{TKPrimitiveGroup, NSView}|null
     */
    private function targetAt(AppkitWindow $window, NSPoint $point): ?array
    {
        $content = $window->native()->contentView();
        if (is_null($content)) {
            return null;
        }
        $owners = [];
        $views = [];
        foreach ($window->registry()->all() as $primitive) {
            foreach (['native', 'placedView'] as $view) {
                if (method_exists($primitive, $view)) {
                    $owners[spl_object_id($primitive->{$view}())] = $primitive;
                    $views[$primitive->uuid()] ??= $primitive->{$view}();
                }
            }
        }

        $frame = $content->superview();
        $hit = $content->hitTest(is_null($frame) ? $point : $frame->convertPointFromView($point, null));
        for ($view = $hit; ! is_null($view) && $view !== $content; $view = $view->superview()) {
            $primitive = $owners[spl_object_id($view)] ?? null;
            if ($primitive instanceof TKPrimitive) {
                $group = TKPrimitiveGroup::clickTarget($primitive);

                return is_null($group) ? null : [$group, $views[$group->uuid()]];
            }
        }

        return null;
    }
}
