<?php

namespace Jovian\Toolkits\Appkit\Menus;

use NSEvent;
use NSMenu;
use NSMenuItem;
use NSTextView;
use NSEventType;
use NSEventModifierFlags;
use ObjCKeyWindow;
use WeakReference;
use Jovian\Toolkits\Appkit\Appkit;
use Surface\Toolkits\Menus\Shortcut;

/**
 * A shortcut with no Primary, Ctrl or Alt goes to the focused widget first. AppKit asks the
 * main menu before it, so for that one event the items with the key have none: the widget
 * gets the key, and when no responder uses it the window's keyDown: hands it to the menu.
 *
 * A text view passes nothing on, so there the menu is asked first for the keys text does not
 * use: F1 to F12, but F5 alone, which is complete: in the system's StandardKeyBinding.dict.
 *
 * @internal
 */
final class AppkitMenuKeys
{
    /** @var array<int, array{NSMenuItem, string, bool}> item, key equivalent, Shift */
    private array $watched = [];

    /** @var list<array{NSMenuItem, string}> without their key while one event is dispatched */
    private array $aside = [];

    public function __construct(
        private readonly Appkit $appkit,
        private readonly ObjCKeyWindow $window,
        private readonly NSMenu $main,
    ) {
        $keys = WeakReference::create($this);
        $appkit->withhold($this, static fn (NSEvent $event): bool => $keys->get()?->pressed($event) ?? false);
        $window->on('keyDown:', static fn (NSEvent $event): bool => $keys->get()?->unused($event) ?? false);
    }

    public function watch(NSMenuItem $item, ?Shortcut $shortcut): void
    {
        unset($this->watched[$item->pointer()]);
        if (! is_null($shortcut) && $shortcut->yieldsToFocus()) {
            $this->watched[$item->pointer()] = [$item, AppkitShortcut::equivalent($shortcut), $shortcut->shift];
        }
    }

    public function release(): void
    {
        $this->appkit->stopWithholding($this);
        $this->window->off('keyDown:');
        [$this->watched, $this->aside] = [[], []];
    }

    /**
     * An event the pump took.
     *
     * @return bool whether it was dispatched here
     */
    public function pressed(NSEvent $event): bool
    {
        if ($event->type() !== NSEventType::KEY_DOWN || $event->windowNumber() !== $this->window->windowNumber()) {
            return false;
        }

        $items = $this->itemsFor($event);
        if ($items === [] || ! $this->focusFirst($event)) {
            return false;
        }

        $this->setAside($items);
        try {
            $this->appkit->get()->sendEvent($event);
        } finally {
            $this->putBack();
        }

        return true;
    }

    /**
     * The window's keyDown:, reached by a key no responder used.
     *
     * @return bool whether the menu took it
     */
    public function unused(NSEvent $event): bool
    {
        if ($this->itemsFor($event) === []) {
            return false;
        }

        $this->putBack();

        return $this->main->performKeyEquivalent($event);
    }

    /**
     * Whether what has the focus may use the key.
     */
    public function focusFirst(NSEvent $event): bool
    {
        if (! $this->window->firstResponder() instanceof NSTextView) {
            return true;
        }

        [$key, $shift] = $this->keyOf($event);
        $code = mb_ord($key);

        return $code < NSF1FunctionKey || $code > NSF12FunctionKey || ($code === NSF5FunctionKey && ! $shift);
    }

    /**
     * @param list<array{NSMenuItem, string}> $items
     */
    private function setAside(array $items): void
    {
        $this->aside = $items;
        foreach ($items as [$item]) {
            $item->setKeyEquivalent('');
        }
    }

    private function putBack(): void
    {
        foreach ($this->aside as [$item, $key]) {
            // Not one that was removed or given another key meanwhile.
            if (($this->watched[$item->pointer()][1] ?? null) === $key) {
                $item->setKeyEquivalent($key);
            }
        }
        $this->aside = [];
    }

    /**
     * @return list<array{NSMenuItem, string}>
     */
    private function itemsFor(NSEvent $event): array
    {
        if ($this->watched === []) {
            return [];
        }

        $others = NSEventModifierFlags::COMMAND->value | NSEventModifierFlags::CONTROL->value | NSEventModifierFlags::OPTION->value;
        if (($event->modifierFlags() & $others) !== 0) {
            return [];
        }

        [$key, $shift] = $this->keyOf($event);
        $items = [];
        foreach ($this->watched as [$item, $its_key, $its_shift]) {
            if ($its_key === $key && $its_shift === $shift) {
                $items[] = [$item, $key];
            }
        }

        return $items;
    }

    /**
     * @return array{string, bool} as a key equivalent is written, and Shift
     */
    private function keyOf(NSEvent $event): array
    {
        $key = (string) $event->charactersIgnoringModifiers();

        return [
            // The Backspace key sends U+007F; its key equivalent is U+0008.
            $key === "\u{7F}" ? mb_chr(NSBackspaceCharacter) : $key,
            ($event->modifierFlags() & NSEventModifierFlags::SHIFT->value) !== 0,
        ];
    }
}
