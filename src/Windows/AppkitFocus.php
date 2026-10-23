<?php

namespace Jovian\Toolkits\Appkit\Windows;

use Closure;
use Jovian\Toolkits\Appkit\Bridge\AppkitSession;
use NSEvent;
use NSEventModifierFlags;
use NSEventType;
use NSView;
use NSWindow;
use Surface\Contracts\HumanInput\InputTap;
use Surface\Contracts\Windows\Mail\View\FocusGained;
use Surface\Contracts\Windows\Mail\View\FocusLost;
use Surface\Contracts\Windows\Primitives\TKCanvas;
use Surface\Contracts\Windows\Primitives\TKPrimitive;
use WeakReference;

/**
 * A toolkit window's keyboard focus as primitives. After each event the session dispatches, the
 * window's firstResponder is compared with the holder last seen; the holder is the primitive whose
 * focus view is the responder or its nearest superview (a text field's field editor sits inside the
 * field). Compared once AppKit has finished with the event: inside one, the responder passes
 * through the window (Return in a text field ends editing, then selects the field again).
 * A move made from code, inside quietly(), posts nothing and only sets the holder, so the next move
 * the user makes posts FocusLost for that holder, then FocusGained for the new one.
 *
 * A tap too: a left press on a focusable canvas makes the canvas first responder before AppKit
 * dispatches the press, as the press on its own view would. A view lent over the canvas (an
 * NSOpenGLView) takes no first responder, so AppKit alone would leave focus where it was.
 */
final class AppkitFocus implements InputTap
{
    /** @var WeakReference<AppkitWindow> the window holds this */
    private readonly WeakReference $window;

    /** @var WeakReference<TKPrimitive>|null the holder last seen: a weak one, so a removed primitive is freed */
    private ?WeakReference $last = null;

    private bool $quiet = false;

    /**
     * The window's first responder when the holder was last read: an event that leaves it as it
     * was (a mouse move) needs no look through the window's primitives.
     * @var WeakReference<object>|null
     */
    private ?WeakReference $responder = null;

    /** Presented but not yet key: present() owns the first responder until the window becomes key. */
    private bool $presenting = false;

    private bool $attached = true;

    public function __construct(
        AppkitWindow $window,
        private readonly NSWindow $native,
        private readonly AppkitSession $session,
    ) {
        $this->window = WeakReference::create($window);
        // The session holds the closure, and the window this tracker: a weak reference keeps it from holding either.
        $self = WeakReference::create($this);
        $session->afterDispatch($this, static function () use ($self): void {
            $self->get()?->changed();
        });
        $session->tap($this);
    }

    /** The primitive whose focus view holds focus; null for none, or for a view Surface did not make. */
    public function holder(): ?TKPrimitive
    {
        $window = $this->window->get();
        if (! $this->attached || ! $window instanceof AppkitWindow || ! $window->isOpen()) {
            return null;
        }
        $responder = $this->native->firstResponder();
        if (! $responder instanceof NSView) {
            return null;
        }
        $owners = [];
        foreach ($window->registry()->all() as $primitive) {
            // Every AppKit primitive (the AppkitPrimitive trait) answers focusView().
            if (! $primitive->isRemoved() && method_exists($primitive, 'focusView')) {
                $owners[spl_object_id($primitive->focusView())] = $primitive;
            }
        }
        for ($at = $responder; $at instanceof NSView; $at = $at->superview()) {
            if (isset($owners[spl_object_id($at)])) {
                return $owners[spl_object_id($at)];
            }
        }

        return null;
    }

    /**
     * Move focus from code: no mail, and the holder after it is the one the user's next move leaves.
     * @param Closure(): void $move
     */
    public function quietly(Closure $move): void
    {
        $this->quiet = true;
        try {
            $move();
        } finally {
            $this->quiet = false;
            $this->remember($this->holder());
        }
    }

    /**
     * present() ordered the window front: an app that is not active yet makes it key on a later
     * event. Until then the first responder is present()'s, and changes to it post nothing.
     */
    public function presented(): void
    {
        $this->presenting = ! $this->native->isKeyWindow();
    }

    /**
     * windowDidBecomeKey: AppKit chose the first responder already (makeKeyAndOrderFront: picks it
     * before the window is key), so it is recorded as present()'s and the user's moves post from here,
     * the click that made the window key included.
     */
    public function becameKey(): void
    {
        if ($this->presenting) {
            $this->presenting = false;
            $this->remember($this->holder());
        }
    }

    public function see(object $native_event): void
    {
        $window = $this->window->get();
        if (! $this->attached || ! $window instanceof AppkitWindow || ! $window->isOpen()) {
            return;
        }
        if (! $native_event instanceof NSEvent
            || $native_event->type() !== NSEventType::LEFT_MOUSE_DOWN
            || $native_event->windowNumber() !== $this->native->windowNumber()
            // Control-click is the right click: a context menu, no focus.
            || ($native_event->modifierFlags() & NSEventModifierFlags::CONTROL->value) !== 0) {
            return;
        }
        [$canvas] = AppkitCanvasClicks::canvasAt($window, $native_event->locationInWindow());
        if ($canvas instanceof TKCanvas && $canvas->isFocusable() && method_exists($canvas, 'focusView')) {
            // The user's move: the comparison after the press posts it.
            $this->native->makeFirstResponder($canvas->focusView());
        }
    }

    /** The window closed: nothing posts after. */
    public function detach(): void
    {
        if (! $this->attached) {
            return;
        }
        $this->attached = false;
        $this->last = null;
        $this->session->untap($this);
        $this->session->afterDispatch($this, null);
    }

    private function changed(): void
    {
        if ($this->quiet || ! $this->attached) {
            return;
        }
        if ($this->presenting) {
            return;
        }
        if ($this->native->firstResponder() === $this->responder?->get()) {
            return;
        }
        $now = $this->holder();
        $last = $this->last?->get();
        if ($now === $last) {
            return;
        }
        $window = $this->window->get();
        $this->remember($now);
        if (! $window instanceof AppkitWindow) {
            return;
        }
        if (! is_null($last) && ! $last->isRemoved()) {
            $this->session->post(new FocusLost($window->name(), $last->path(), $last->uuid()));
        }
        if (! is_null($now)) {
            $this->session->post(new FocusGained($window->name(), $now->path(), $now->uuid()));
        }
    }

    private function remember(?TKPrimitive $holder): void
    {
        $this->last = is_null($holder) ? null : WeakReference::create($holder);
        $responder = $this->attached ? $this->native->firstResponder() : null;
        $this->responder = is_null($responder) ? null : WeakReference::create($responder);
    }
}
