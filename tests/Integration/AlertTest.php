<?php

declare(strict_types=1);

use Jovian\Toolkits\Appkit\AppkitWindow;
use Surface\Contracts\Toolkits\Dialogs\Alert;
use Surface\Contracts\Toolkits\Dialogs\AlertStyle;

/** A confirmation whose default is also its cancel button, as one that deletes something asks. */
function confirmation(?string $suppression = null): Alert
{
    return new Alert('Clear your key?', 'It goes back to demo mode.', ['Clear Key', 'Cancel'], default: 1, cancel: 1, style: AlertStyle::Warning, suppression: $suppression);
}

/** The NSAlert the window shows, once AppKit attached its sheet. */
function shownAlert(AppkitWindow $window): NSAlert
{
    if (! pumpUntil(fn (): bool => ! is_null($window->native()->attachedSheet()))) {
        throw new RuntimeException("No sheet attached to '{$window->name()}'.");
    }

    return (fn (): ?NSAlert => $this->alert)->call($window);
}

/**
 * The NSAlert the window shows, once its sheet is the key window. AppKit reports Return on the
 * default button only while the sheet is key, so its keys are read only then. The window is made
 * active first, which macOS may decline: see requireActive().
 */
function keyAlert(AppkitWindow $window, Alert $alert): NSAlert
{
    requireActive($window);
    $window->alert($alert);
    $native = shownAlert($window);
    if (! pumpUntil(fn (): bool => $native->window()->isKeyWindow())) {
        throw new RuntimeException("The alert on '{$window->name()}' did not become the key window.");
    }

    return $native;
}

it('shows an NSAlert sheet on the window and returns at once', function (): void {
    $window = openWindow();
    takeMail();

    $id = $window->alert(confirmation());
    $native = shownAlert($window);

    expect($id)->toMatch('/^[0-9a-f]{16}$/')
        ->and($native->messageText())->toBe('Clear your key?')
        ->and($native->informativeText())->toBe('It goes back to demo mode.')
        ->and($native->alertStyle())->toBe(NSAlertStyle::WARNING)
        ->and($window->native()->attachedSheet()?->windowNumber())->toBe($native->window()->windowNumber())
        ->and(mailNames(takeMail()))->not->toContain('AlertAnswered');
});

it('gives Return to the default button and Esc to none when the default is also the cancel', function (): void {
    $native = keyAlert(openWindow(), confirmation());

    expect(array_map(fn (NSButton $b): string => $b->title(), $native->buttons()))->toBe(['Clear Key', 'Cancel'])
        ->and(array_map(fn (NSButton $b): string => $b->keyEquivalent(), $native->buttons()))->toBe(['', "\r"]);
});

it('gives Esc to the cancel button when it is not the default', function (): void {
    $native = keyAlert(openWindow(), new Alert('Save?', buttons: ['Save', 'Don\'t Save', 'Cancel']));

    expect(array_map(fn (NSButton $b): string => $b->keyEquivalent(), $native->buttons()))->toBe(["\r", '', "\e"]);
});

it('posts the index of the button pressed, under the alert\'s id', function (): void {
    $window = openWindow();
    takeMail();
    $id = $window->alert(confirmation());
    $native = shownAlert($window);

    $native->buttons()[0]->performClick(null);
    $answer = array_values(array_filter(mailUntil('AlertAnswered'), fn (object $m): bool => $m instanceof Surface\Contracts\Toolkits\Mail\Windows\AlertAnswered));

    expect($answer)->toHaveCount(1)
        ->and($answer[0]->id)->toBe($id)
        ->and($answer[0]->window)->toBe('main')
        ->and($answer[0]->button)->toBe(0)
        ->and($answer[0]->suppressed)->toBeFalse()
        ->and($window->isAlerting())->toBeFalse()
        ->and(pumpUntil(fn (): bool => is_null($window->native()->attachedSheet())))->toBeTrue();
});

it('posts whether the suppression box was ticked', function (): void {
    $window = openWindow();
    takeMail();
    $window->alert(confirmation("Don't ask again"));
    $native = shownAlert($window);

    expect($native->showsSuppressionButton())->toBeTrue()
        ->and($native->suppressionButton()->title())->toBe("Don't ask again");
    $native->suppressionButton()->setState(NSControlStateValue::ON);
    $native->buttons()[1]->performClick(null);
    $answer = array_values(array_filter(mailUntil('AlertAnswered'), fn (object $m): bool => $m instanceof Surface\Contracts\Toolkits\Mail\Windows\AlertAnswered));

    expect($answer[0]->button)->toBe(1)
        ->and($answer[0]->suppressed)->toBeTrue();
});

it('ends the sheet and answers -1 when the window closes first', function (): void {
    $window = openWindow();
    takeMail();
    $id = $window->alert(confirmation());
    shownAlert($window);

    $window->close();
    $mail = takeMail();
    pumpFor(0.1);
    $later = takeMail();

    $answers = array_values(array_filter($mail, fn (object $m): bool => $m instanceof Surface\Contracts\Toolkits\Mail\Windows\AlertAnswered));
    expect($answers)->toHaveCount(1)
        ->and($answers[0]->id)->toBe($id)
        ->and($answers[0]->button)->toBe(-1)
        ->and(mailNames($mail))->toContain('WindowClosed')
        ->and(mailNames($later))->not->toContain('AlertAnswered');
});
