<?php

namespace Jovian\Toolkits\Appkit\Primitives;

use Jovian\Toolkits\Appkit\Primitives\Concerns\AppkitPrimitive;
use NSApplication;
use NSComboBox;
use NSEvent;
use NSEventType;
use NSObject;
use NSRect;
use ObjCDelegate;
use ObjCTarget;
use Surface\Contracts\Windows\Mail\View\SelectionChanged;
use Surface\Contracts\Windows\Mail\View\TextChanged;
use Surface\Contracts\Windows\Mail\View\TextSubmitted;
use Surface\Contracts\Windows\Primitives\Placement;
use Surface\Contracts\Windows\ToolkitWindow;
use Surface\Windows\Primitives\TKComboBox;
use Surface\Windows\Primitives\TKPrimitiveGroup;
use WeakReference;

/**
 * An NSComboBox for the options, and an AppkitCompletionList under it for the completions:
 * AppKit opens a combo's own list only for the user, so the completions need a list of their own.
 * One delegate answers the field: controlTextDidChange: posts TextChanged, comboBoxSelectionDidChange:
 * a choice of an option, controlTextDidEndEditing: hides the completions, and
 * control:textView:doCommandBySelector: takes Up, Down, Return and Esc while they show. The action
 * posts TextSubmitted for Return or keypad Enter, as AppkitTextInput's does.
 */
class AppkitComboBox extends TKComboBox
{
    use AppkitPrimitive;

    private const int RETURN = 36;

    private const int ENTER = 76;

    /** Controls hold delegates and targets weakly: the primitive keeps both. */
    protected ObjCDelegate $delegate;

    protected ObjCTarget $target;

    protected AppkitCompletionList $list;

    /** Set while the driver writes the native: its echoes post nothing. */
    private bool $applying = false;

    public function __construct(string $name, ToolkitWindow $window, ?TKPrimitiveGroup $parent, Placement $placement, string $value, array $options)
    {
        parent::__construct($name, $window, $parent, $placement, $value, $options);
        $combo = $this->adoptNative(NSComboBox::initWithFrame(new NSRect(0.0, 0.0, 0.0, 0.0)));
        $this->applyOptions($this->options);
        $combo->setStringValue($value);

        // The delegate, target and list hold their callables where PHP's collector cannot see them: a weak reference keeps them from holding the primitive.
        $self = WeakReference::create($this);
        $this->list = new AppkitCompletionList(static fn (int $row) => $self->get()?->chooseCompletion($row));
        $this->delegate = new ObjCDelegate('NSComboBoxDelegate');
        $this->delegate->on('controlTextDidChange:', static fn () => $self->get()?->typed());
        $this->delegate->on('comboBoxSelectionDidChange:', static fn () => $self->get()?->optionChosen());
        $this->delegate->on('controlTextDidEndEditing:', static fn () => $self->get()?->editingEnded());
        $this->delegate->on('control:textView:doCommandBySelector:', static fn (NSObject $control, NSObject $view, string $selector): bool => $self->get()?->command($selector) ?? false);
        $combo->setDelegate($this->delegate);

        $this->target = new ObjCTarget(static fn () => $self->get()?->submitted());
        $combo->setTarget($this->target);
        $combo->setAction(ObjCTarget::ACTION);
    }

    public function native(): NSComboBox
    {
        return $this->native;
    }

    /**
     * An NSComboBox answers acceptsFirstResponder NO, yet makeFirstResponder: takes it and opens
     * its field editor (probe 2026-10-23): the combo skips the question the other primitives ask.
     */
    protected function applyFocus(): bool
    {
        $window = $this->host()->native();
        if ($this->native->window() !== $window) {
            return false;
        }
        $taken = false;
        $this->host()->focusTracker()->quietly(function () use ($window, &$taken): void {
            $taken = $window->makeFirstResponder($this->native);
        });

        return $taken;
    }

    /** The list the completions show in, for reads. */
    public function completionList(): AppkitCompletionList
    {
        return $this->list;
    }

    protected function applyValue(string $value): void
    {
        $this->list->hide();
        $this->applying = true;
        try {
            $this->native->setStringValue($value);
        } finally {
            $this->applying = false;
        }
    }

    protected function applyOptions(array $options): void
    {
        $this->applying = true;
        try {
            $this->native->removeAllItems();
            foreach ($options as $option) {
                $this->native->addItemWithObjectValue($option);
            }
        } finally {
            $this->applying = false;
        }
    }

    protected function applyCompletions(array $completions): bool
    {
        if (! $this->nativeHasFocus()) {
            $this->list->hide();

            return false;
        }
        if ($completions === []) {
            $this->list->hide();

            return true;
        }
        $this->list->show($this->native, $completions);

        return true;
    }

    protected function releaseNative(): void
    {
        $this->list->close();
        $this->native->setDelegate(null);
        $this->native->setTarget(null);
    }

    protected function typed(): void
    {
        if ($this->applying) {
            return;
        }
        $this->nativeEdited($this->native->stringValue());
        $this->session()->post(new TextChanged($this->window->name(), $this->path(), $this->uuid, $this->value));
    }

    protected function optionChosen(): void
    {
        $index = $this->native->indexOfSelectedItem();
        if ($this->applying || $index < 0 || ! isset($this->options[$index])) {
            return;
        }
        $this->list->hide();
        $this->nativeChose($this->options[$index]);
        $this->session()->post(new SelectionChanged($this->window->name(), $this->path(), $this->uuid, $index, $this->value));
    }

    protected function chooseCompletion(int $row): void
    {
        $text = $this->list->rows()[$row] ?? null;
        if (is_null($text)) {
            return;
        }
        $this->list->hide();
        $this->applying = true;
        try {
            $this->native->setStringValue($text);
        } finally {
            $this->applying = false;
        }
        $this->nativeChose($text);
        $this->session()->post(new SelectionChanged($this->window->name(), $this->path(), $this->uuid, -1, $text));
    }

    protected function editingEnded(): void
    {
        if ($this->list->isShown()) {
            $this->list->hide();
            $this->nativeHidden();
        }
    }

    /** The field editor's command for a key; true when the completion list took it. */
    protected function command(string $selector): bool
    {
        if (! $this->list->isShown()) {
            return false;
        }

        switch ($selector) {
            case 'moveDown:':
                $this->list->move(1);

                return true;
            case 'moveUp:':
                $this->list->move(-1);

                return true;
            case 'insertNewline:':
                $row = $this->list->highlighted();
                if ($row < 0) {
                    return false;
                }
                $this->chooseCompletion($row);

                return true;
            case 'cancelOperation:':
                $this->list->hide();
                $this->nativeHidden();

                return true;
        }

        return false;
    }

    protected function submitted(): void
    {
        $event = NSApplication::sharedApplication()->currentEvent();
        if (! $event instanceof NSEvent || $event->type() !== NSEventType::KEY_DOWN || ! in_array($event->keyCode(), [self::RETURN, self::ENTER], true)) {
            return;
        }
        $this->list->hide();
        $this->nativeEdited($this->native->stringValue());
        $this->nativeHidden();
        $this->session()->post(new TextSubmitted($this->window->name(), $this->path(), $this->uuid, $this->value));
    }
}
