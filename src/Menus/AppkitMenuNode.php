<?php

namespace Jovian\Toolkits\Appkit\Menus;

use NSMenu;
use NSMenuItem;
use ObjCTarget;
use Surface\Contracts\Toolkits\Menus\BarNode;
use Surface\Contracts\Toolkits\Menus\BarNodeKind;

/**
 * What an AppkitMenuBar made for one node.
 *
 * @internal
 */
final class AppkitMenuNode
{
    public readonly BarNodeKind $kind;

    public bool $enabled;

    public ?NSMenu $submenu = null;

    /** The action while enabled; none for a menu and a line. */
    public ?string $action = null;

    /** @var list<NSMenuItem> one, or a choice's one per option */
    public array $natives = [];

    /** @var list<ObjCTarget> kept: a menu item holds its target weakly */
    public array $targets = [];

    /** @var list<string> a choice's values, as its natives are ordered */
    public array $options = [];

    /** @var list<string> a menu's nodes, in order */
    public array $children = [];

    public function __construct(BarNode $node, public ?string $parent)
    {
        $this->kind = $node->kind;
        $this->enabled = $node->enabled;
    }
}
