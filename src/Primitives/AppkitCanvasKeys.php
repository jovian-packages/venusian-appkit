<?php

declare(strict_types=1);

namespace Jovian\Toolkits\Appkit\Primitives;

use Jovian\Toolkits\Appkit\Input\AppkitInputEngine;
use Jovian\Toolkits\Appkit\Windows\AppkitWindow;
use Jovian\Toolkits\Appkit\Windows\AppkitWindowKeys;
use NSEvent;
use NSEventMask;
use NSEventModifierFlags;
use NSObject;
use ObjCFocusView;
use Surface\Contracts\HumanInput\Key;
use Surface\Contracts\Windows\Input\EditCommand;
use Surface\Contracts\Windows\Mail\View\EditCommanded;
use Surface\Contracts\Windows\Mail\View\KeyPressed;
use Surface\Contracts\Windows\Mail\View\KeyReleased;
use Surface\Contracts\Windows\Mail\View\PrimitiveEventOccurred;
use Surface\Contracts\Windows\Mail\View\TextCommitted;
use Surface\HumanInput\Keymaps\MacKeycodes;
use WeakReference;

/**
 * The keys of a focused canvas, from its focus view: keyDown: posts KeyPressed and hands the event
 * to interpretKeyEvents:, whose insertText: posts TextCommitted and whose doCommandBySelector:
 * posts EditCommanded from the selector table; keyUp: posts KeyReleased; flagsChanged: posts a
 * lone modifier's press and release. AppKit sends the view these only while it is first responder,
 * after key equivalents (menu accelerators, role buttons) had the key. Tab and Shift-Tab are
 * declined when the canvas does not take Tab, so NSWindow moves focus along the key-view loop.
 */
final class AppkitCanvasKeys
{
    private const int TAB = 48;

    /** NSEvent's device-independent modifier flags (the modifier keys as flags, Caps Lock and fn included). */
    private const int DEVICE_INDEPENDENT = 0xFFFF0000;

    /** Each modifier key's code (Carbon kVK_) to its NSEventModifierFlags bit. */
    private const array MODIFIER_FLAGS = [
        56 => 1 << 17, 60 => 1 << 17, 59 => 1 << 18, 62 => 1 << 18, 58 => 1 << 19, 61 => 1 << 19,
        55 => 1 << 20, 54 => 1 << 20, 57 => 1 << 16, 63 => 1 << 23,
    ];

    /** The Edit menu's actions; a focus view answers them while handled, so their items enable while the canvas is focused. */
    private const array ACTIONS = ['cut:' => EditCommand::Cut, 'copy:' => EditCommand::Copy, 'paste:' => EditCommand::Paste, 'selectAll:' => EditCommand::SelectAll, 'undo:' => EditCommand::Undo, 'redo:' => EditCommand::Redo];

    private const array SELECTORS = ['keyDown:', 'keyUp:', 'flagsChanged:', 'insertText:replacementRange:', 'doCommandBySelector:', 'cut:', 'copy:', 'paste:', 'selectAll:', 'undo:', 'redo:'];

    /** NSStandardKeyBindingResponding selectors to commands; a selector not here (noop:, transpose:, Home's scroll) posts nothing. */
    private const array COMMANDS = [
        'moveLeft:' => EditCommand::MoveLeft, 'moveBackward:' => EditCommand::MoveLeft,
        'moveRight:' => EditCommand::MoveRight, 'moveForward:' => EditCommand::MoveRight,
        'moveUp:' => EditCommand::MoveUp, 'moveDown:' => EditCommand::MoveDown,
        'moveWordLeft:' => EditCommand::MoveWordLeft, 'moveWordBackward:' => EditCommand::MoveWordLeft,
        'moveWordRight:' => EditCommand::MoveWordRight, 'moveWordForward:' => EditCommand::MoveWordRight,
        'moveToLeftEndOfLine:' => EditCommand::MoveToLineStart, 'moveToBeginningOfLine:' => EditCommand::MoveToLineStart, 'moveToBeginningOfParagraph:' => EditCommand::MoveToLineStart,
        'moveToRightEndOfLine:' => EditCommand::MoveToLineEnd, 'moveToEndOfLine:' => EditCommand::MoveToLineEnd, 'moveToEndOfParagraph:' => EditCommand::MoveToLineEnd,
        'moveToBeginningOfDocument:' => EditCommand::MoveToDocumentStart, 'moveToEndOfDocument:' => EditCommand::MoveToDocumentEnd,
        'pageUp:' => EditCommand::PageUp, 'scrollPageUp:' => EditCommand::PageUp,
        'pageDown:' => EditCommand::PageDown, 'scrollPageDown:' => EditCommand::PageDown,
        'moveLeftAndModifySelection:' => EditCommand::SelectLeft, 'moveBackwardAndModifySelection:' => EditCommand::SelectLeft,
        'moveRightAndModifySelection:' => EditCommand::SelectRight, 'moveForwardAndModifySelection:' => EditCommand::SelectRight,
        'moveUpAndModifySelection:' => EditCommand::SelectUp, 'moveDownAndModifySelection:' => EditCommand::SelectDown,
        'moveWordLeftAndModifySelection:' => EditCommand::SelectWordLeft, 'moveWordBackwardAndModifySelection:' => EditCommand::SelectWordLeft,
        'moveWordRightAndModifySelection:' => EditCommand::SelectWordRight, 'moveWordForwardAndModifySelection:' => EditCommand::SelectWordRight,
        'moveToLeftEndOfLineAndModifySelection:' => EditCommand::SelectToLineStart, 'moveToBeginningOfLineAndModifySelection:' => EditCommand::SelectToLineStart, 'moveToBeginningOfParagraphAndModifySelection:' => EditCommand::SelectToLineStart,
        'moveToRightEndOfLineAndModifySelection:' => EditCommand::SelectToLineEnd, 'moveToEndOfLineAndModifySelection:' => EditCommand::SelectToLineEnd, 'moveToEndOfParagraphAndModifySelection:' => EditCommand::SelectToLineEnd,
        'moveToBeginningOfDocumentAndModifySelection:' => EditCommand::SelectToDocumentStart, 'moveToEndOfDocumentAndModifySelection:' => EditCommand::SelectToDocumentEnd,
        'pageUpAndModifySelection:' => EditCommand::SelectPageUp, 'pageDownAndModifySelection:' => EditCommand::SelectPageDown,
        'deleteBackward:' => EditCommand::DeleteBackward, 'deleteForward:' => EditCommand::DeleteForward,
        'deleteWordBackward:' => EditCommand::DeleteWordBackward, 'deleteWordForward:' => EditCommand::DeleteWordForward,
        'deleteToEndOfLine:' => EditCommand::DeleteToLineEnd, 'deleteToEndOfParagraph:' => EditCommand::DeleteToLineEnd,
        'yank:' => EditCommand::Yank, 'selectAll:' => EditCommand::SelectAll,
        'insertNewline:' => EditCommand::InsertNewline, 'insertNewlineIgnoringFieldEditor:' => EditCommand::InsertNewline,
        'insertLineBreak:' => EditCommand::InsertNewline, 'insertParagraphSeparator:' => EditCommand::InsertNewline,
        'insertTab:' => EditCommand::InsertTab, 'insertTabIgnoringFieldEditor:' => EditCommand::InsertTab,
        'insertBacktab:' => EditCommand::InsertBacktab, 'cancelOperation:' => EditCommand::Cancel,
    ];

    private readonly WeakReference $canvas;

    /** @var array<int, true> Key codes whose KeyPressed posted and whose KeyReleased has not. */
    private array $held = [];

    /** @var array<string, int> Modifier keys (Key values) reported pressed, with their flag bit. */
    private array $modifiers = [];

    private ?NSObject $monitor;

    private bool $attached = true;

    public function __construct(AppkitCanvas $canvas, private readonly ObjCFocusView $view)
    {
        $this->canvas = WeakReference::create($canvas);
        // The view holds the handlers, and the canvas holds the view and this: weak references break the cycles.
        $self = WeakReference::create($this);
        $view->on('keyDown:', static fn (NSEvent $event): bool => $self->get()?->down($event) ?? false);
        $view->on('keyUp:', static fn (NSEvent $event): bool => $self->get()?->up($event) ?? false);
        $view->on('flagsChanged:', static fn (NSEvent $event): bool => $self->get()?->flagsChanged($event) ?? false);
        $view->on('insertText:replacementRange:', static fn (string $text): bool => $self->get()?->inserted($text) ?? false);
        $view->on('doCommandBySelector:', static fn (string $selector): bool => $self->get()?->commanded($selector) ?? false);
        foreach (array_keys(self::ACTIONS) as $action) {
            $view->on($action, static fn (?NSObject $sender): bool => $self->get()?->acted($action) ?? false);
        }
        // NSApplication sends no keyUp: to a window while Command is down; the monitor sees it first.
        $this->monitor = NSEvent::addLocalMonitorForEventsMatchingMaskHandler(
            NSEventMask::KEY_UP->value,
            static function (NSEvent $event) use ($self): NSEvent {
                $self->get()?->commandUp($event);

                return $event;
            },
        );
    }

    public function detach(): void
    {
        if ($this->attached) {
            $this->attached = false;
            foreach (self::SELECTORS as $selector) {
                $this->view->off($selector);
            }
            if (! is_null($this->monitor)) {
                NSEvent::removeMonitor($this->monitor);
                $this->monitor = null;
            }
        }
    }

    private function down(NSEvent $event): bool
    {
        $canvas = $this->canvas();
        if (is_null($canvas)) {
            return false;
        }
        if ($event->keyCode() === self::TAB && ! $canvas->takesTab()) {
            return false;
        }
        $this->post($canvas, static fn (string $window, string $path, string $uuid): KeyPressed => new KeyPressed(
            $window, $path, $uuid,
            MacKeycodes::key($event->keyCode()),
            AppkitWindowKeys::character($event->charactersIgnoringModifiers() ?? ''),
            AppkitInputEngine::modifiers($event->modifierFlags()),
            $event->isARepeat(),
        ));
        $this->held[$event->keyCode()] = true;
        $this->view->interpretKeyEvents([$event]);

        return true;
    }

    private function up(NSEvent $event): bool
    {
        $canvas = $this->canvas();
        if (is_null($canvas)) {
            return false;
        }
        $this->release($canvas, $event);

        return true;
    }

    /** A key-up with Command down, before NSApplication drops it: the release of a key this canvas took while it is first responder. */
    private function commandUp(NSEvent $event): void
    {
        $canvas = $this->canvas();
        if (! is_null($canvas) && ($event->modifierFlags() & NSEventModifierFlags::COMMAND->value) !== 0
            && $event->window() === $this->view->window() && $this->view->window()?->firstResponder() === $this->view) {
            $this->release($canvas, $event);
        }
    }

    /** KeyReleased for a key whose KeyPressed posted, once. */
    private function release(AppkitCanvas $canvas, NSEvent $event): void
    {
        if (! isset($this->held[$event->keyCode()])) {
            return;
        }
        unset($this->held[$event->keyCode()]);
        $this->post($canvas, static fn (string $window, string $path, string $uuid): KeyReleased => new KeyReleased(
            $window, $path, $uuid, MacKeycodes::key($event->keyCode()), AppkitInputEngine::modifiers($event->modifierFlags()),
        ));
    }

    /** An Edit menu action reached the focused canvas: its command. */
    private function acted(string $action): bool
    {
        $canvas = $this->canvas();
        if (is_null($canvas)) {
            return false;
        }
        $command = self::ACTIONS[$action];
        $this->post($canvas, static fn (string $window, string $path, string $uuid): EditCommanded => new EditCommanded($window, $path, $uuid, $command));

        return true;
    }

    /**
     * A modifier key, by its own flag: set is its press, clear its release. Then every modifier
     * reported pressed whose flag is gone is released, whichever key the event names (focus that
     * left mid-chord comes back as one change naming key code 0).
     */
    private function flagsChanged(NSEvent $event): bool
    {
        $canvas = $this->canvas();
        if (is_null($canvas)) {
            return false;
        }
        $now = $event->modifierFlags() & self::DEVICE_INDEPENDENT;
        $modifiers = AppkitInputEngine::modifiers($event->modifierFlags());
        $bit = self::MODIFIER_FLAGS[$event->keyCode()] ?? 0;
        if ($bit !== 0 && ($now & $bit) !== 0) {
            $key = MacKeycodes::key($event->keyCode());
            $this->modifiers[$key->value] = $bit;
            $this->post($canvas, static fn (string $window, string $path, string $uuid): KeyPressed => new KeyPressed($window, $path, $uuid, $key, '', $modifiers, false));
        }
        $released = $bit !== 0 && ($now & $bit) === 0 ? MacKeycodes::key($event->keyCode())->value : null;
        foreach ($this->modifiers as $value => $flag) {
            if ($value === $released || ($now & $flag) === 0) {
                unset($this->modifiers[$value]);
                $key = Key::from($value);
                $this->post($canvas, static fn (string $window, string $path, string $uuid): KeyReleased => new KeyReleased($window, $path, $uuid, $key, $modifiers));
            }
        }

        return true;
    }

    private function inserted(string $text): bool
    {
        $canvas = $this->canvas();
        if (is_null($canvas)) {
            return false;
        }
        $this->post($canvas, static fn (string $window, string $path, string $uuid): TextCommitted => new TextCommitted($window, $path, $uuid, $text));

        return true;
    }

    /** A mapped selector posts its command; any other is taken and posts nothing (no beep from NSResponder). */
    private function commanded(string $selector): bool
    {
        $canvas = $this->canvas();
        if (is_null($canvas)) {
            return false;
        }
        $command = self::COMMANDS[$selector] ?? null;
        if (! is_null($command)) {
            $this->post($canvas, static fn (string $window, string $path, string $uuid): EditCommanded => new EditCommanded($window, $path, $uuid, $command));
        }

        return true;
    }

    /** The canvas while it is attached, placed and in an open window. */
    private function canvas(): ?AppkitCanvas
    {
        $canvas = $this->canvas->get();
        if (! $this->attached || ! $canvas instanceof AppkitCanvas || $canvas->isRemoved()) {
            return null;
        }
        $window = $canvas->window();

        return $window instanceof AppkitWindow && $window->isOpen() ? $canvas : null;
    }

    /** @param \Closure(string, string, string): PrimitiveEventOccurred $mail */
    private function post(AppkitCanvas $canvas, \Closure $mail): void
    {
        /** @var AppkitWindow $window */
        $window = $canvas->window();
        $window->session()->post($mail($window->name(), $canvas->path(), $canvas->uuid()));
    }
}
