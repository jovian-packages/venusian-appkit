<?php

namespace Jovian\Toolkits\Appkit\UI;

use NSRect;
use NSObject;
use NSComboBox;
use ObjCDelegate;
use WeakReference;
use NSLayoutConstraint;
use Surface\Toolkits\UI\TKComboBox;
use Surface\Contracts\Toolkits\UI\TKTransition;
use Jovian\Toolkits\Appkit\UI\Concerns\AppkitNative;
use Jovian\Toolkits\Appkit\UI\Concerns\TakesInputFromAbove;
use Jovian\Toolkits\Appkit\UI\Support\AppkitCompletionList;

/**
 * An NSComboBox for the options, and an AppkitCompletionList under it for the completions
 * (AppKit opens a combo's own list only for the user). The field editor's commands move and
 * choose in that list, and Return otherwise submits.
 */
class AppkitComboBox extends TKComboBox
{
    use AppkitNative {
        releaseNative as private releaseView;
        applyVisible as private applyViewVisible;
    }
    use TakesInputFromAbove;

    private const float NATURAL_WIDTH = 120.0;

    /** Controls hold their delegates weakly: the primitive keeps it. */
    private ?ObjCDelegate $delegate = null;

    private ?AppkitCompletionList $list = null;

    private ?NSLayoutConstraint $natural_width = null;

    protected function bootstrapNative(): void
    {
        $combo = NSComboBox::initWithFrame(new NSRect(0.0, 0.0, 0.0, 0.0));
        $this->adoptNative($combo);
        $this->natural_width = $combo->widthAnchor()->constraintGreaterThanOrEqualToConstant(self::NATURAL_WIDTH);
        NSLayoutConstraint::activateConstraints([$this->natural_width]);

        $this->applyOptions($this->options());
        $combo->setStringValue($this->value());

        $self = WeakReference::create($this);
        $this->list = new AppkitCompletionList(static function (int $row) use ($self): void {
            $self->get()?->chooseRow($row);
        });
        $this->delegate = new ObjCDelegate('NSComboBoxDelegate');
        $this->delegate->on('controlTextDidChange:', static function () use ($self): void {
            $self->get()?->edited();
        });
        $this->delegate->on('comboBoxSelectionDidChange:', static function () use ($self): void {
            $box = $self->get();
            $box?->choseOption($box->comboNative()->indexOfSelectedItem());
        });
        $this->delegate->on('controlTextDidEndEditing:', static function () use ($self): void {
            $self->get()?->completionsHidden();
        });
        $this->delegate->on(
            'control:textView:doCommandBySelector:',
            static fn (NSObject $control, NSObject $view, string $selector): bool => $self->get()?->command($selector) ?? false,
        );
        $combo->setDelegate($this->delegate);
    }

    /**
     * An NSComboBox answers acceptsFirstResponder NO, and takes it all the same.
     */
    protected function nativeFocus(): bool
    {
        $window = $this->native()->window();
        if (is_null($window) || $window !== $this->window()->native()) {
            return false;
        }

        $window->makeFirstResponder($this->native());

        return $this->hasFocus();
    }

    protected function applyVisible(bool $visible, ?TKTransition $transition, float $seconds): void
    {
        if (! $visible) {
            $this->completionsHidden();
        }
        $this->applyViewVisible($visible, $transition, $seconds);
    }

    protected function applyValue(string $value): void
    {
        $this->comboNative()->setStringValue($value);
    }

    protected function applyOptions(array $options): void
    {
        $combo = $this->comboNative();
        $combo->removeAllItems();
        foreach ($options as $option) {
            $combo->addItemWithObjectValue($option);
        }
    }

    protected function applyCompletions(array $completions): void
    {
        $completions === [] ? $this->list->hide() : $this->list->show($this->native(), $completions);
    }

    protected function applyNativeEnabled(bool $takes_input): void
    {
        $this->comboNative()->setEnabled($takes_input);
    }

    protected function releaseNative(): void
    {
        $this->list->close();
        $this->list = null;
        $this->comboNative()->setDelegate(null);
        $this->delegate = null;
        NSLayoutConstraint::deactivateConstraints([$this->natural_width]);
        $this->natural_width = null;
        $this->releaseView();
    }

    /**
     * Typed text is no option any more: deselected, so choosing that option again is a change.
     */
    private function edited(): void
    {
        $combo = $this->comboNative();
        $text = $combo->stringValue();
        $selected = $combo->indexOfSelectedItem();

        if ($selected >= 0 && ! $this->isWriting() && ($this->options()[$selected] ?? null) !== $text) {
            $this->write(function () use ($combo, $selected, $text): void {
                $combo->deselectItemAtIndex($selected);
                if ($combo->stringValue() !== $text) {
                    $combo->setStringValue($text);
                }
            });
        }

        $this->typed($text);
    }

    private function chooseRow(int $row): void
    {
        $text = $this->list?->rows()[$row] ?? null;
        if (! is_null($text)) {
            $this->choseCompletion($text);
        }
    }

    /**
     * The field editor's command for a key; true when the completion list took it.
     */
    private function command(string $selector): bool
    {
        $shown = $this->list->isShown();

        if ($shown && ($selector === 'moveDown:' || $selector === 'moveUp:')) {
            $this->list->move($selector === 'moveDown:' ? 1 : -1);

            return true;
        }
        if ($shown && $selector === 'cancelOperation:') {
            $this->completionsHidden();

            return true;
        }
        if ($selector === 'insertNewline:') {
            $row = $this->list->highlighted();
            if ($row >= 0) {
                $this->chooseRow($row);

                return true;
            }
            $this->submitted($this->comboNative()->stringValue());
        }

        return false;
    }

    private function comboNative(): NSComboBox
    {
        /** @var NSComboBox */
        return $this->native();
    }
}
