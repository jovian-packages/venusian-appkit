<?php

declare(strict_types=1);

use Jovian\Toolkits\Appkit\Primitives\AppkitTree;
use Surface\Contracts\Windows\Mail\View\FocusLost;
use Surface\Contracts\Windows\Mail\View\NodeActivated;
use Surface\Contracts\Windows\Mail\View\NodeExpanded;
use Surface\Contracts\Windows\Mail\View\NodeSelected;
use Surface\Contracts\Windows\Primitives\TableColumn;
use Surface\Contracts\Windows\Primitives\TreeNode;
use Surface\NutsAndBolts\Color;

beforeEach(function (): void {
    driver()->closeAll();
    pumpFor(0.05);
    takeMail(session());
});

afterEach(function (): void {
    driver()->closeAll();
    pumpFor(0.05);
    takeMail(session());
});

/** @return list<TreeNode> a(a1(a1x), a2), b, with a2's name in red */
function treeNodes(): array
{
    return [
        new TreeNode('a', ['name' => 'a', 'size' => '3'], [
            new TreeNode('a1', ['name' => 'a1'], [new TreeNode('a1x', ['name' => 'a1x'])]),
            new TreeNode('a2', ['name' => 'a2', 'size' => '7'], colors: ['name' => Color::rgb(255, 0, 0)]),
        ]),
        new TreeNode('b', ['name' => 'b']),
    ];
}

/** A tree in a presented window, the presenting mail taken. */
function presentedTree(array $nodes = []): AppkitTree
{
    $window = driver()->open('main', 400, 300);
    $tree = $window->column('m')->tree('t', [new TableColumn('name', 'Name'), new TableColumn('size', 'Size')], $nodes === [] ? treeNodes() : $nodes);
    $window->present();
    pumpFor(0.2);
    takeMail(session());

    return $tree;
}

/** A key-down at the tree's window, posted to the front of the queue and pumped through. */
function pressKey(AppkitTree $tree, string $characters, int $code): void
{
    $event = NSEvent::keyEventWithTypeLocationModifierFlagsTimestampWindowNumberContextCharactersCharactersIgnoringModifiersIsARepeatKeyCode(
        NSEventType::KEY_DOWN, new NSPoint(0.0, 0.0), 0, 0.0, $tree->outline()->window()->windowNumber(), null, $characters, $characters, false, $code,
    );
    NSApplication::sharedApplication()->postEventAtStart($event, true);
    pumpFor(0.1);
}

it('builds an outline column per column, the first the outline column, and shows each cell with its colour', function (): void {
    $tree = presentedTree();
    $outline = $tree->outline();
    $tree->expand('a');
    $row = $outline->rowForItem($tree->item('a2'));
    $name = $outline->viewAtColumnRowMakeIfNecessary(0, $row, true);
    $size = $outline->viewAtColumnRowMakeIfNecessary(1, $row, true);
    $color = $name->textColor();

    expect($tree->native())->toBeInstanceOf(NSScrollView::class)
        ->and($tree->native()->documentView())->toBe($outline)
        ->and(array_map(fn (NSTableColumn $column): array => [$column->identifier(), $column->title()], $outline->tableColumns()))->toBe([['name', 'Name'], ['size', 'Size']])
        ->and($outline->outlineTableColumn()->identifier())->toBe('name')
        ->and($outline->numberOfRows())->toBe(4)
        ->and($name)->toBeInstanceOf(NSTextField::class)
        ->and([$name->stringValue(), $size->stringValue()])->toBe(['a2', '7'])
        ->and([$color->redComponent(), $color->greenComponent(), $color->blueComponent()])->toBe([1.0, 0.0, 0.0])
        ->and($outline->viewAtColumnRowMakeIfNecessary(1, $outline->rowForItem($tree->item('a1')), true)->stringValue())->toBe('')
        ->and($tree->item('nope'))->toBeNull();
});

it('posts nothing for expand, selectNode and setNodes, and the native agrees', function (): void {
    $tree = presentedTree();
    $outline = $tree->outline();
    $tree->expand('a');
    $tree->selectNode('a1x');
    $selected = $outline->itemAtRow($outline->selectedRow())->UTF8String();
    $tree->setNodes([new TreeNode('a', ['name' => 'a'], [new TreeNode('a1', ['name' => 'a1'], [new TreeNode('a1x', ['name' => 'a1x'])])])]);
    pumpFor(0.1);

    expect(viewMail(session()))->toBe([])
        ->and($selected)->toBe('a1x')
        ->and($tree->selectedNode())->toBe('a1x')
        ->and($outline->isItemExpanded($tree->item('a')))->toBeTrue()
        ->and($outline->isItemExpanded($tree->item('a1')))->toBeTrue()
        ->and($outline->numberOfRows())->toBe(3);

    $tree->expand('a', false);
    $tree->selectNode(null);
    pumpFor(0.1);
    expect(viewMail(session()))->toBe([])
        ->and($outline->isItemExpanded($tree->item('a')))->toBeFalse()
        ->and($outline->selectedRow())->toBe(-1);
});

it('posts NodeExpanded for the user\'s expand and collapse, and reopens what was expanded below', function (): void {
    $tree = presentedTree();
    $outline = $tree->outline();
    $outline->expandItem($tree->item('a'));
    $outline->expandItem($tree->item('a1'));
    $outline->collapseItem($tree->item('a'));
    $outline->expandItem($tree->item('a'));
    $outline->expandItem($tree->item('b'));
    pumpFor(0.1);

    expect(viewMail(session()))->toEqual([
        new NodeExpanded('main', 'm.t', $tree->uuid(), 'a', true),
        new NodeExpanded('main', 'm.t', $tree->uuid(), 'a1', true),
        new NodeExpanded('main', 'm.t', $tree->uuid(), 'a', false),
        new NodeExpanded('main', 'm.t', $tree->uuid(), 'a', true),
    ])
        ->and($outline->isItemExpanded($tree->item('a1')))->toBeTrue()
        ->and($tree->isExpanded('a1'))->toBeTrue()
        ->and($outline->numberOfRows())->toBe(5);

    // Code collapses a1 while a is closed: AppKit's own reopening of a1 with a is undone, unposted.
    $outline->collapseItem($tree->item('a'));
    $tree->expand('a1', false);
    $outline->expandItem($tree->item('a'));
    pumpFor(0.1);
    expect(viewMail(session()))->toEqual([
        new NodeExpanded('main', 'm.t', $tree->uuid(), 'a', false),
        new NodeExpanded('main', 'm.t', $tree->uuid(), 'a', true),
    ])
        ->and($outline->isItemExpanded($tree->item('a1')))->toBeFalse()
        ->and($tree->isExpanded('a1'))->toBeFalse()
        ->and($outline->numberOfRows())->toBe(4);
});

it('posts NodeSelected for the user\'s selection, and for a collapse only when the selected node changed', function (): void {
    $tree = presentedTree();
    $outline = $tree->outline();
    $tree->expand('a');
    $outline->selectRowIndexesByExtendingSelection(NSIndexSet::indexSetWithIndex($outline->rowForItem($tree->item('a2'))), false);
    pumpFor(0.1);
    expect(viewMail(session()))->toEqual([new NodeSelected('main', 'm.t', $tree->uuid(), 'a2')]);

    $outline->collapseItem($tree->item('a'));
    pumpFor(0.1);
    $after = $tree->selectedNode();
    $selections = array_values(array_filter(viewMail(session()), fn (object $mail): bool => $mail instanceof NodeSelected));
    expect($selections)->toEqual($after === 'a2' ? [] : [new NodeSelected('main', 'm.t', $tree->uuid(), $after)]);

    $outline->selectRowIndexesByExtendingSelection(NSIndexSet::indexSetWithIndex($outline->rowForItem($tree->item('b'))), false);
    $outline->selectRowIndexesByExtendingSelection(NSIndexSet::indexSetWithIndex($outline->rowForItem($tree->item('b'))), false);
    pumpFor(0.1);
    expect(viewMail(session()))->toEqual([new NodeSelected('main', 'm.t', $tree->uuid(), 'b')]);
});

it('posts NodeActivated for Return and Enter on the selected node while the outline is first responder, and swallows the key', function (): void {
    $tree = presentedTree();
    $outline = $tree->outline();
    $tree->selectNode('b');
    $outline->window()->makeFirstResponder($outline);
    pressKey($tree, mb_chr(NSCarriageReturnCharacter), 36);
    pressKey($tree, mb_chr(NSEnterCharacter), 76);
    $activated = viewMail(session());

    // A move made natively, not through focus(): the focus tracker posts it.
    $outline->window()->makeFirstResponder(null);
    pressKey($tree, mb_chr(NSCarriageReturnCharacter), 36);

    expect($activated)->toEqual([
        new NodeActivated('main', 'm.t', $tree->uuid(), 'b'),
        new NodeActivated('main', 'm.t', $tree->uuid(), 'b'),
    ])
        ->and(viewMail(session()))->toEqual([new FocusLost('main', 'm.t', $tree->uuid())])
        ->and($outline->doubleAction())->toBe(ObjCTarget::ACTION);
});

it('keeps a selection setNodes moved under a collapsed node, opening its ancestors, posting nothing', function (): void {
    $tree = presentedTree();
    $tree->selectNode('a1');
    $tree->setNodes([new TreeNode('a', ['name' => 'a'], [new TreeNode('a2', ['name' => 'a2'])]), new TreeNode('b', ['name' => 'b'], [new TreeNode('a1', ['name' => 'a1'])])]);
    pumpFor(0.1);

    expect($tree->selectedNode())->toBe('a1')
        ->and($tree->outline()->isItemExpanded($tree->item('b')))->toBeTrue()
        ->and($tree->isExpanded('b'))->toBeTrue()
        ->and(viewMail(session()))->toBe([]);
});

it('posts the user\'s selection of a node that code deselected by collapsing its parent', function (): void {
    $tree = presentedTree();
    $outline = $tree->outline();
    $tree->expand('a');
    $outline->selectRowIndexesByExtendingSelection(NSIndexSet::indexSetWithIndex($outline->rowForItem($tree->item('a2'))), false);
    $tree->expand('a', false);
    $outline->expandItem($tree->item('a'));
    $outline->selectRowIndexesByExtendingSelection(NSIndexSet::indexSetWithIndex($outline->rowForItem($tree->item('a2'))), false);
    pumpFor(0.1);

    expect(array_values(array_filter(viewMail(session()), fn (object $mail): bool => $mail instanceof NodeSelected)))->toEqual([
        new NodeSelected('main', 'm.t', $tree->uuid(), 'a2'),
        new NodeSelected('main', 'm.t', $tree->uuid(), 'a2'),
    ]);
});

// AppKit does not let a disabled outline be first responder, so the key monitor never takes Return for it.
it('takes no Return on a disabled tree', function (): void {
    $tree = presentedTree();
    $outline = $tree->outline();
    $tree->selectNode('b');
    $outline->window()->makeFirstResponder($outline);
    $tree->setEnabled(false);
    $outline->window()->makeFirstResponder($outline);
    pressKey($tree, mb_chr(NSCarriageReturnCharacter), 36);

    expect(viewMail(session()))->toBe([])
        ->and([$outline->window()->firstResponder() === $outline, $outline->isEnabled(), $tree->selectedNode()])->toBe([false, false, 'b']);
});

it('takes a later expand of a child AppKit closed for real while hidden as the user\'s', function (): void {
    $tree = presentedTree();
    $outline = $tree->outline();
    $outline->expandItem($tree->item('a'));
    $outline->expandItem($tree->item('a1'));
    $outline->collapseItem($tree->item('a'));
    // AppKit forgets hidden a1 behind the driver's back, as an Option-click collapse does, so it
    // will not reopen it with a; the record closes it too.
    $tree->expand('a1', false);
    $outline->reloadData();
    $outline->expandItem($tree->item('a'));
    NSApplication::sharedApplication()->postEventAtStart(NSEvent::otherEventWithTypeLocationModifierFlagsTimestampWindowNumberContextSubtypeData1Data2(
        NSEventType::APPLICATION_DEFINED, new NSPoint(0.0, 0.0), 0, 0.0, 0, null, 0, 0, 0,
    ), true);
    pumpFor(0.1);
    viewMail(session());
    $outline->expandItem($tree->item('a1'));
    pumpFor(0.1);

    expect(viewMail(session()))->toEqual([new NodeExpanded('main', 'm.t', $tree->uuid(), 'a1', true)])
        ->and($outline->isItemExpanded($tree->item('a1')))->toBeTrue()
        ->and($tree->isExpanded('a1'))->toBeTrue();
});

it('is freed once removed', function (): void {
    $tree = presentedTree();
    $tree->expand('a');
    $freed = WeakReference::create($tree);
    $tree->remove();
    unset($tree);
    pumpFor(0.1);
    gc_collect_cycles();

    expect($freed->get())->toBeNull();
});

it('drops the selection when setNodes removes the selected node, posting nothing', function (): void {
    $tree = presentedTree();
    $tree->selectNode('b');
    $tree->setNodes([new TreeNode('a', ['name' => 'a'])]);
    pumpFor(0.1);

    expect($tree->selectedNode())->toBeNull()
        ->and($tree->outline()->selectedRow())->toBe(-1)
        ->and($tree->outline()->numberOfRows())->toBe(1)
        ->and($tree->item('b'))->toBeNull()
        ->and(viewMail(session()))->toBe([]);
});
