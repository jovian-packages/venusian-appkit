<?php

declare(strict_types=1);

use Surface\Contracts\Windows\Primitives\TableColumn;
use Surface\Contracts\Windows\Primitives\TKPrimitiveGroup;
use Surface\Contracts\Windows\Primitives\TreeNode;

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

// The ext's native holders (ObjCDelegate, ObjCTarget, KVO observers, notification blocks, event
// monitors) keep their callables where PHP's collector cannot see them: a primitive must not be
// reachable from them, or removing it never frees it.
it('frees a removed primitive', function (Closure $build): void {
    $window = driver()->open('main', 400, 300);
    $host = $window->column('m');
    $window->present();
    pumpFor(0.1);
    $primitive = $build($host);
    pumpFor(0.1);
    $freed = WeakReference::create($primitive);
    $primitive->remove();
    unset($primitive);
    pumpFor(0.1);
    gc_collect_cycles();

    expect($freed->get())->toBeNull();
})->with([
    'label' => [fn (TKPrimitiveGroup $m) => $m->label('p', 'text')],
    'link' => [fn (TKPrimitiveGroup $m) => $m->link('p', 'text', 'https://example.com')],
    'button' => [fn (TKPrimitiveGroup $m) => $m->button('p', 'Go')],
    'image' => [fn (TKPrimitiveGroup $m) => $m->image('p')],
    'canvas' => [fn (TKPrimitiveGroup $m) => $m->canvas('p')],
    'focused canvas with keys' => [function (TKPrimitiveGroup $m) {
        $canvas = $m->canvas('p')->minSize(100, 40)->setFocusable();
        $canvas->focus();
        pumpFor(0.1);
        press(0);

        return $canvas;
    }],
    'separator' => [fn (TKPrimitiveGroup $m) => $m->separator('p')],
    'spinner' => [fn (TKPrimitiveGroup $m) => $m->spinner('p')],
    'progress bar' => [fn (TKPrimitiveGroup $m) => $m->progressBar('p', 0.5)],
    'text input' => [fn (TKPrimitiveGroup $m) => $m->textInput('p', 'x')],
    'text area' => [fn (TKPrimitiveGroup $m) => $m->textArea('p', "a\nb")->watchScroll(true)],
    'checkbox' => [fn (TKPrimitiveGroup $m) => $m->checkbox('p', 'Check')],
    'toggle' => [fn (TKPrimitiveGroup $m) => $m->toggle('p')],
    'toggle button' => [fn (TKPrimitiveGroup $m) => $m->toggleButton('p', 'Press')],
    'slider' => [fn (TKPrimitiveGroup $m) => $m->slider('p', 0.0, 10.0, 5.0)],
    'scroll bar' => [fn (TKPrimitiveGroup $m) => $m->scrollBar('p')->setRange(100, 10)],
    'dropdown' => [fn (TKPrimitiveGroup $m) => $m->dropdown('p', ['a', 'b'])],
    'combo box' => [fn (TKPrimitiveGroup $m) => $m->comboBox('p', '', ['a'])->focus()->setCompletions(['/a', '/b'])],
    'menu button' => [fn (TKPrimitiveGroup $m) => $m->menuButton('p', 'Menu', [['label' => 'One']])],
    'image button' => [fn (TKPrimitiveGroup $m) => $m->button('p', 'Go')->setImage((new Surface\Framebuffers\Native\NativeFullFramebuffer(Surface\Contracts\Framebuffers\FormatSpec::rgba8(), 4, 4)))],
    'datepicker' => [fn (TKPrimitiveGroup $m) => $m->datepicker('p')],
    'table' => [fn (TKPrimitiveGroup $m) => $m->table('p', [new TableColumn('n', 'N')], [['n' => 'a']])],
    'tree' => [fn (TKPrimitiveGroup $m) => $m->tree('p', [new TableColumn('n', 'N')], [new TreeNode('a', ['n' => 'a'])])],
    'video' => [fn (TKPrimitiveGroup $m) => $m->video('p')],
    'column' => [fn (TKPrimitiveGroup $m) => $m->column('p')],
    'row' => [fn (TKPrimitiveGroup $m) => $m->row('p')],
    'grid' => [fn (TKPrimitiveGroup $m) => $m->grid('p')],
    'fixed' => [fn (TKPrimitiveGroup $m) => $m->fixed('p')],
    'scroll view' => [fn (TKPrimitiveGroup $m) => $m->scrollView('p')],
    'split' => [fn (TKPrimitiveGroup $m) => $m->split('p')],
    'tabs' => [fn (TKPrimitiveGroup $m) => $m->tabs('p')],
]);
