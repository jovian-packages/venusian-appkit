<?php

namespace Jovian\Toolkits\Appkit\Menus;

use NSMenu;
use NSMenuItem;
use NSControlStateValue;
use ObjCTarget;
use RuntimeException;
use WeakReference;
use Jovian\Toolkits\Appkit\AppkitWindow;
use Jovian\Toolkits\Appkit\AppkitSession;
use Surface\Toolkits\Menus\Shortcut;
use Surface\Toolkits\Menus\Runtime\ToolkitMenuBar;
use Surface\Contracts\Toolkits\ToolkitPoweredWindow;
use Surface\Contracts\Toolkits\Menus\BarNode;
use Surface\Contracts\Toolkits\Menus\BarNodeKind;
use Surface\Contracts\Toolkits\Menus\StandardKind;

/**
 * An NSMenu per window, and one for when none is key. macOS shows one main menu, so show()
 * installs this one.
 *
 * Submenus validate their items (the edit and window items are validated by whoever answers
 * them), and there AppKit ignores setEnabled(): an item is disabled by having no action, a
 * submenu by an action its target does not answer.
 *
 * macOS puts items of its own at the end of some menus (Edit gets dictation and the character
 * palette), so a node is placed after the one before it and never by counting natives.
 * It adds some of them again, hidden, each time a bar is installed: show() drops those repeats.
 */
class AppkitMenuBar extends ToolkitMenuBar
{
    /** An action nothing implements: what shows a submenu disabled inside a validating menu. */
    private const string NOBODY_ANSWERS = 'venusianDisabledMenu:';

    protected NSMenu $main;

    /** @var array<string, AppkitMenuNode> */
    protected array $nodes = [];

    /** @var list<string> the bar's menus, in order */
    protected array $top = [];

    /** The target of an enabled submenu: it does nothing. */
    protected ObjCTarget $opens;

    /** Null for the bar shown when no window is key: there is no focus to ask first. */
    protected ?AppkitMenuKeys $keys = null;

    public function __construct(AppkitSession $session, ?ToolkitPoweredWindow $window = null)
    {
        parent::__construct($session, $window);

        $this->main = NSMenu::initWithTitle('MainMenu');
        $this->main->setAutoenablesItems(false);
        $this->opens = new ObjCTarget(static function (): void {});

        if ($window instanceof AppkitWindow) {
            $this->keys = new AppkitMenuKeys($session->application(), $window->native(), $this->main);
        }
    }

    public function menu(): NSMenu
    {
        return $this->main;
    }

    /**
     * The natives of one node: one, or a choice's one per option.
     *
     * @return list<NSMenuItem>
     */
    public function natives(string $uuid): array
    {
        return $this->node($uuid)->natives;
    }

    public function insert(?string $parent, int $index, BarNode $node): void
    {
        $into = $this->menuOf($parent);
        $made = new AppkitMenuNode($node, $parent);

        match ($node->kind) {
            BarNodeKind::SEPARATOR => $made->natives = [NSMenuItem::separatorItem()],
            BarNodeKind::MENU => $this->makeMenu($made, $node),
            BarNodeKind::CHOICE => $this->makeChoice($made, $node),
            BarNodeKind::STANDARD => $this->makeStandard($made, $node),
            BarNodeKind::ITEM, BarNodeKind::TOGGLE => $this->makeItem($made, $node, $this->chooses($node->uuid)),
        };

        $this->applyShortcut($made, $node->shortcut);
        $this->applyMark($made, $node->on, $node->chosen);

        $this->place($made, $into, $index);
        $siblings = &$this->siblings($parent);
        array_splice($siblings, $index, 0, [$node->uuid]);
        $this->nodes[$node->uuid] = $made;

        $this->applyEnabled($made, $node->enabled);
    }

    public function remove(string $uuid): void
    {
        $node = $this->node($uuid);
        $this->takeOut($node, $this->menuOf($node->parent));

        $siblings = &$this->siblings($node->parent);
        array_splice($siblings, (int) array_search($uuid, $siblings, true), 1);

        $this->forget($uuid);
    }

    public function move(string $uuid, ?string $parent, int $index): void
    {
        $node = $this->node($uuid);
        $into = $this->menuOf($parent);
        $this->takeOut($node, $this->menuOf($node->parent));

        $from = &$this->siblings($node->parent);
        array_splice($from, (int) array_search($uuid, $from, true), 1);
        unset($from);

        $node->parent = $parent;
        $this->place($node, $into, $index);
        $to = &$this->siblings($parent);
        array_splice($to, $index, 0, [$uuid]);

        $this->applyEnabled($node, $node->enabled);
    }

    public function change(string $uuid, array $changes): void
    {
        $node = $this->node($uuid);

        if (array_key_exists('label', $changes) && $node->kind !== BarNodeKind::CHOICE) {
            $node->natives[0]->setTitle($changes['label']);
            $node->submenu?->setTitle($changes['label']);
        }
        if (array_key_exists('enabled', $changes)) {
            $this->applyEnabled($node, $changes['enabled']);
        }
        if (array_key_exists('on', $changes) || array_key_exists('chosen', $changes)) {
            $this->applyMark($node, $changes['on'] ?? null, $changes['chosen'] ?? null);
        }
        if (array_key_exists('shortcut', $changes)) {
            $this->applyShortcut($node, $changes['shortcut']);
        }
    }

    public function show(): void
    {
        $this->appkit()->application()->get()->setMainMenu($this->main);
        $this->dropRepeats();
    }

    public function release(): void
    {
        $this->keys?->release();
        $this->keys = null;
        $this->main->removeAllItems();
        [$this->nodes, $this->top] = [[], []];
    }

    /**
     * AppKit does every kind.
     */
    public function supports(StandardKind $kind): bool
    {
        return true;
    }

    /**
     * Remove what macOS added that repeats an item above it exactly (title, action and keys).
     * Never a node's own native, and never the first of its kind.
     */
    protected function dropRepeats(): void
    {
        $own = [];
        foreach ($this->nodes as $node) {
            foreach ($node->natives as $native) {
                $own[$native->pointer()] = true;
            }
        }

        foreach ($this->nodes as $node) {
            if (is_null($menu = $node->submenu)) {
                continue;
            }

            [$seen, $repeats] = [[], []];
            for ($index = 0, $count = $menu->numberOfItems(); $index < $count; $index++) {
                $item = $menu->itemAtIndex($index);
                if ($item->isSeparatorItem()) {
                    continue;
                }

                $same = implode("\0", [$item->title(), $item->action() ?? '', $item->keyEquivalent(), $item->keyEquivalentModifierMask()]);
                if (isset($seen[$same]) && ! isset($own[$item->pointer()])) {
                    $repeats[] = $item;
                }
                $seen[$same] = true;
            }

            foreach ($repeats as $repeat) {
                $menu->removeItem($repeat);
            }
        }
    }

    protected function makeMenu(AppkitMenuNode $made, BarNode $node): void
    {
        $slot = NSMenuItem::initWithTitleActionKeyEquivalent($node->label, null, '');
        $made->submenu = NSMenu::initWithTitle($node->label);
        $slot->setSubmenu($made->submenu);
        $made->natives = [$slot];
    }

    protected function makeChoice(AppkitMenuNode $made, BarNode $node): void
    {
        $made->action = ObjCTarget::ACTION;
        foreach ($node->options as $value => $label) {
            $native = NSMenuItem::initWithTitleActionKeyEquivalent((string) $label, ObjCTarget::ACTION, '');
            $target = new ObjCTarget($this->chooses($node->uuid, (string) $value));
            $native->setTarget($target);
            $made->natives[] = $native;
            $made->targets[] = $target;    // NSMenuItem holds its target weakly
            $made->options[] = (string) $value;
        }
    }

    protected function makeStandard(AppkitMenuNode $made, BarNode $node): void
    {
        if (! $node->replaced && ! is_null($action = self::actionOf($node->standard))) {
            // No target: AppKit sends it along the responder chain, from what has the focus.
            $made->action = $action;
            $made->natives = [NSMenuItem::initWithTitleActionKeyEquivalent($node->label, $action, '')];

            return;
        }

        if (! $node->replaced && ! is_null($panel = $node->panel)) {
            $session = WeakReference::create($this->appkit());
            $this->makeItem($made, $node, static function () use ($session, $panel): void {
                $session->get()?->showAbout($panel);
            });

            return;
        }

        $this->makeItem($made, $node, $this->chooses($node->uuid));
    }

    protected function makeItem(AppkitMenuNode $made, BarNode $node, callable $run): void
    {
        $native = NSMenuItem::initWithTitleActionKeyEquivalent($node->label, ObjCTarget::ACTION, '');
        $target = new ObjCTarget($run);
        $native->setTarget($target);

        $made->action = ObjCTarget::ACTION;
        $made->natives = [$native];
        $made->targets = [$target];    // NSMenuItem holds its target weakly
    }

    /**
     * What a native's target runs. It holds the bar weakly: the bar holds the target.
     */
    protected function chooses(string $uuid, ?string $option = null): callable
    {
        $bar = WeakReference::create($this);

        return static function () use ($bar, $uuid, $option): void {
            $bar->get()?->activated($uuid, $option);
        };
    }

    protected function applyEnabled(AppkitMenuNode $node, bool $enabled): void
    {
        $node->enabled = $enabled;

        if ($node->kind === BarNodeKind::SEPARATOR) {
            return;
        }

        if (is_null($node->parent)) {
            $node->natives[0]->setEnabled($enabled);    // the bar does not validate

            return;
        }

        if (! is_null($node->submenu)) {
            $slot = $node->natives[0];
            $slot->setTarget($enabled ? $this->opens : $slot);
            $slot->setAction($enabled ? ObjCTarget::ACTION : self::NOBODY_ANSWERS);

            return;
        }

        foreach ($node->natives as $native) {
            $native->setAction($enabled ? $node->action : null);
        }
    }

    protected function applyMark(AppkitMenuNode $node, ?bool $on, ?string $chosen): void
    {
        foreach ($node->natives as $at => $native) {
            $marked = match ($node->kind) {
                BarNodeKind::TOGGLE => $on === true,
                BarNodeKind::CHOICE => ! is_null($chosen) && $node->options[$at] === $chosen,
                default => null,
            };
            if (! is_null($marked)) {
                $native->setState($marked ? NSControlStateValue::ON : NSControlStateValue::OFF);
            }
        }
    }

    protected function applyShortcut(AppkitMenuNode $node, ?Shortcut $shortcut): void
    {
        if (in_array($node->kind, [BarNodeKind::MENU, BarNodeKind::CHOICE, BarNodeKind::SEPARATOR], true)) {
            return;
        }

        $native = $node->natives[0];
        $native->setKeyEquivalent(is_null($shortcut) ? '' : AppkitShortcut::equivalent($shortcut));
        $native->setKeyEquivalentModifierMask(is_null($shortcut) ? 0 : AppkitShortcut::mask($shortcut));
        $this->keys?->watch($native, $shortcut);
    }

    /**
     * Put $node's natives in $menu so it is the $index-th of its siblings: after the last
     * native of the one before it.
     */
    protected function place(AppkitMenuNode $node, NSMenu $menu, int $index): void
    {
        $at = 0;
        $siblings = $this->siblings($node->parent);
        for ($before = $index - 1; $before >= 0; $before--) {
            if ($natives = $this->node($siblings[$before])->natives) {
                $at = $menu->indexOfItem($natives[count($natives) - 1]) + 1;
                break;
            }
        }

        foreach ($node->natives as $native) {
            $menu->insertItemAtIndex($native, $at++);
        }
    }

    protected function takeOut(AppkitMenuNode $node, NSMenu $menu): void
    {
        foreach ($node->natives as $native) {
            $menu->removeItem($native);
        }
    }

    protected function forget(string $uuid): void
    {
        $node = $this->node($uuid);
        foreach ($node->children as $child) {
            $this->forget($child);
        }
        foreach ($node->natives as $native) {
            $this->keys?->watch($native, null);
        }
        unset($this->nodes[$uuid]);
    }

    /**
     * @return list<string>
     */
    protected function &siblings(?string $parent): array
    {
        if (is_null($parent)) {
            return $this->top;
        }

        $node = $this->node($parent);

        return $node->children;
    }

    protected function menuOf(?string $parent): NSMenu
    {
        if (is_null($parent)) {
            return $this->main;
        }

        return $this->node($parent)->submenu
            ?? throw new RuntimeException("Menu node '{$parent}' is not a menu: nothing goes inside it.");
    }

    protected function node(string $uuid): AppkitMenuNode
    {
        return $this->nodes[$uuid] ?? throw new RuntimeException("This bar has no menu node '{$uuid}'.");
    }

    protected function appkit(): AppkitSession
    {
        /** @var AppkitSession */
        return $this->session;
    }

    /**
     * AppKit's own action for the kinds it does by itself.
     */
    private static function actionOf(StandardKind $kind): ?string
    {
        return match ($kind) {
            StandardKind::HIDE => 'hide:',
            StandardKind::HIDE_OTHERS => 'hideOtherApplications:',
            StandardKind::SHOW_ALL => 'unhideAllApplications:',
            StandardKind::MINIMIZE => 'performMiniaturize:',
            StandardKind::CLOSE_WINDOW => 'performClose:',
            StandardKind::FULLSCREEN => 'toggleFullScreen:',
            StandardKind::UNDO => 'undo:',
            StandardKind::REDO => 'redo:',
            StandardKind::CUT => 'cut:',
            StandardKind::COPY => 'copy:',
            StandardKind::PASTE => 'paste:',
            StandardKind::SELECT_ALL => 'selectAll:',
            StandardKind::ABOUT, StandardKind::PREFERENCES, StandardKind::QUIT => null,
        };
    }
}
