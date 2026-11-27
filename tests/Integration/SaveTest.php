<?php

declare(strict_types=1);

use Jovian\Toolkits\Appkit\AppkitWindow;
use Surface\Contracts\Toolkits\Dialogs\SaveRequest;
use Surface\Contracts\Toolkits\Mail\Windows\SaveAnswered;

/*
| A save panel is an NSSavePanel shown as a sheet on the window. The panel's own buttons
| cannot be pressed from a test without a click: endSheet:returnCode: ends it as OK or Cancel,
| the way its buttons do.
*/

/** The NSSavePanel the window shows, once AppKit attached its sheet. */
function shownSavePanel(AppkitWindow $window): NSSavePanel
{
    if (! pumpUntil(fn (): bool => ! is_null($window->native()->attachedSheet()))) {
        throw new RuntimeException("No sheet attached to '{$window->name()}'.");
    }

    return (fn (): ?NSSavePanel => $this->save)->call($window);
}

/** @return list<SaveAnswered> */
function saveAnswers(array $mail): array
{
    return array_values(array_filter($mail, fn (object $m): bool => $m instanceof SaveAnswered));
}

afterEach(fn () => closeAll());

it('shows an NSSavePanel sheet with the name, folder and title, and returns at once', function (): void {
    $window = openWindow();
    takeMail();

    $id = $window->saveFile(new SaveRequest('report.txt', sys_get_temp_dir(), 'Export the report'));
    $panel = shownSavePanel($window);

    expect($id)->toMatch('/^[0-9a-f]{16}$/')
        ->and($window->isSaving())->toBeTrue()
        ->and($panel->nameFieldStringValue())->toBe('report.txt')
        ->and($panel->message())->toBe('Export the report')
        ->and(realpath($panel->directoryURL()->path()))->toBe(realpath(sys_get_temp_dir()))
        ->and($window->native()->attachedSheet()?->windowNumber())->toBe($panel->windowNumber())
        ->and(saveAnswers(takeMail()))->toBe([]);
});

it('posts null, under the id, when the panel is cancelled', function (): void {
    $window = openWindow();
    takeMail();
    $id = $window->saveFile(new SaveRequest('report.txt'));
    $panel = shownSavePanel($window);

    $window->native()->endSheetReturnCode($panel, NSModalResponseCancel);
    $answers = saveAnswers(mailUntil('SaveAnswered'));

    expect($answers)->toHaveCount(1)
        ->and($answers[0]->id)->toBe($id)
        ->and($answers[0]->window)->toBe('main')
        ->and($answers[0]->path)->toBeNull()
        ->and($window->isSaving())->toBeFalse()
        ->and(pumpUntil(fn (): bool => is_null($window->native()->attachedSheet())))->toBeTrue();
});

it('posts the path the panel ends with when it is accepted', function (): void {
    $window = openWindow();
    takeMail();
    $window->saveFile(new SaveRequest('report.txt', sys_get_temp_dir()));
    $panel = shownSavePanel($window);

    $window->native()->endSheetReturnCode($panel, NSModalResponseOK);
    $answers = saveAnswers(mailUntil('SaveAnswered'));

    expect($answers)->toHaveCount(1)
        ->and($answers[0]->path)->toBe($panel->URL()?->path())
        ->and(basename((string) $answers[0]->path))->toBe('report.txt');
});

it('takes the panel down and answers null once when the window closes first', function (): void {
    $window = openWindow();
    takeMail();
    $id = $window->saveFile(new SaveRequest('report.txt'));
    shownSavePanel($window);

    $window->close();
    $mail = takeMail();
    pumpFor(0.1);
    $later = takeMail();

    $answers = saveAnswers($mail);
    expect($answers)->toHaveCount(1)
        ->and($answers[0]->id)->toBe($id)
        ->and($answers[0]->path)->toBeNull()
        ->and(mailNames($mail))->toContain('WindowClosed')
        ->and(saveAnswers($later))->toBe([]);
});
