<?php

declare(strict_types=1);

use Surface\Contracts\Windows\Mail\Services\AlertAnswered;
use Surface\Contracts\Windows\Mail\Services\FilesChosen;
use Surface\Contracts\Windows\Mail\WindowCloseRequested;
use Surface\Contracts\Windows\Mail\WindowClosed;
use Surface\Contracts\Windows\Services\Alert;
use Surface\Contracts\Windows\Services\AlertStyle;
use Surface\Contracts\Windows\Services\DialogCapability;
use Surface\Contracts\Windows\Services\OpenRequest;
use Surface\Contracts\Windows\Services\SaveRequest;
use Surface\Contracts\Windows\WindowException;

beforeEach(function (): void {
    driver()->closeAll();
    pumpFor(0.05);
    takeMail(session());
});

/** A key-down at a sheet (Return 36, keypad Enter 76, Esc 53), posted to the front of the queue and pumped through the session. */
function sheetKey(NSWindow $sheet, int $code): void
{
    $characters = match ($code) {
        36 => mb_chr(NSCarriageReturnCharacter),
        76 => mb_chr(NSEnterCharacter),
        default => "\e",
    };
    $event = NSEvent::keyEventWithTypeLocationModifierFlagsTimestampWindowNumberContextCharactersCharactersIgnoringModifiersIsARepeatKeyCode(
        NSEventType::KEY_DOWN, new NSPoint(0.0, 0.0), 0, 0.0, $sheet->windowNumber(), null, $characters, $characters, false, $code,
    );
    NSApplication::sharedApplication()->postEventAtStart($event, true);
    pumpFor(0.1);
}

/** The dialog and close mail the session holds, in order, as short strings. */
function dialogMail(): array
{
    return array_values(array_filter(array_map(fn (object $mail): ?string => match (true) {
        $mail instanceof AlertAnswered => "alert:{$mail->button}" . ($mail->suppressed ? ':suppressed' : ''),
        $mail instanceof FilesChosen => 'files:' . implode(',', $mail->paths),
        $mail instanceof WindowClosed => "closed:{$mail->window}",
        $mail instanceof WindowCloseRequested => "requested:{$mail->window}",
        default => null,
    }, takeMail(session()))));
}

/** A shown main window with the sheet of $show attached. */
function withSheet(Closure $show): array
{
    $main = driver()->open('main', 480, 320);
    $main->present();
    $id = $show($main);
    pumpUntil(fn (): bool => ! is_null($main->native()->attachedSheet()), 2.0);
    pumpFor(0.3);

    return [$main, $id, $main->native()->attachedSheet()];
}

it('shows an alert as a sheet and posts nothing until it is answered', function (): void {
    [$main, $id, $sheet] = withSheet(fn ($main) => driver()->dialogs()->alert($main, new Alert('Save?', 'Two files', ['Save', 'Discard', 'Cancel'], default: 1)));

    expect($sheet)->toBeInstanceOf(NSPanel::class)
        ->and(dialogMail())->toBe([]);

    sheetKey($sheet, 36);

    expect(pumpUntil(fn (): bool => is_null($main->native()->attachedSheet()), 2.0))->toBeTrue()
        ->and(dialogMail())->toBe(['alert:1']);
});

it('answers Esc with the cancel button and keypad Enter with the default', function (int $code, int $answer): void {
    [$main, $id, $sheet] = withSheet(fn ($main) => driver()->dialogs()->alert($main, new Alert('Save?', null, ['Save', 'Discard', 'Cancel'], default: 1)));
    sheetKey($sheet, $code);
    pumpUntil(fn (): bool => is_null($main->native()->attachedSheet()), 2.0);

    expect(dialogMail())->toBe(["alert:{$answer}"]);
})->with(['Esc' => [53, 2], 'keypad Enter' => [76, 1]]);

it('answers Return and Esc with the one button', function (int $code): void {
    [$main, $id, $sheet] = withSheet(fn ($main) => driver()->dialogs()->alert($main, new Alert('Done.')));
    sheetKey($sheet, $code);
    pumpUntil(fn (): bool => is_null($main->native()->attachedSheet()), 2.0);

    expect(dialogMail())->toBe(['alert:0']);
})->with(['Return' => 36, 'Esc' => 53]);

it('answers -1 when the parent closes, then closes it, and nothing more', function (): void {
    [$main, $id] = withSheet(fn ($main) => driver()->dialogs()->alert($main, new Alert('Save?')));
    $main->close();
    pumpFor(0.6);

    expect(dialogMail())->toBe(['alert:-1', 'closed:main']);
});

it('answers the alert of a child before the child and its parent close', function (): void {
    $main = driver()->open('main', 480, 320);
    $main->present();
    $note = driver()->open('note', 300, 160, null, $main);
    $note->present();
    pumpUntil(fn (): bool => $main->native()->attachedSheet() === $note->native(), 2.0);
    driver()->dialogs()->alert($note, new Alert('Save?'));
    pumpUntil(fn (): bool => ! is_null($note->native()->attachedSheet()), 2.0);
    $main->close();
    pumpFor(0.6);

    expect(dialogMail())->toBe(['alert:-1', 'closed:note', 'closed:main']);
});

it('refuses a second modal on a parent with an open dialog, and keeps the user close', function (): void {
    [$main] = withSheet(fn ($main) => driver()->dialogs()->alert($main, new Alert('Save?')));
    $main->confirmClose();

    expect(fn () => driver()->dialogs()->alert($main, new Alert('Again')))->toThrow(WindowException::class, "'main'")
        ->and(fn () => driver()->dialogs()->openFiles($main, new OpenRequest()))->toThrow(WindowException::class, "'main'")
        ->and(fn () => driver()->open('child', 300, 120, null, $main))->toThrow(WindowException::class, "'main'");

    $main->native()->performClose(null);
    pumpFor(0.2);

    expect(dialogMail())->toBe([])
        ->and($main->isOpen())->toBeTrue();
});

it('refuses a dialog on a parent with an open child', function (): void {
    $main = driver()->open('main', 480, 320);
    driver()->open('child', 300, 120, null, $main);

    expect(fn () => driver()->dialogs()->alert($main, new Alert('Save?')))->toThrow(WindowException::class, "'main'");
});

it('sets an open panel from the request and answers [] when it is cancelled', function (): void {
    [$main, $id, $panel] = withSheet(fn ($main) => driver()->dialogs()->openFiles($main, new OpenRequest('Pick', __DIR__, multiple: true)));

    expect($panel)->toBeInstanceOf(NSOpenPanel::class)
        ->and($panel->canChooseFiles())->toBeTrue()
        ->and($panel->canChooseDirectories())->toBeFalse()
        ->and($panel->allowsMultipleSelection())->toBeTrue()
        ->and($panel->directoryURL()->path())->toBe(realpath(__DIR__))
        ->and(dialogMail())->toBe([]);

    $main->native()->endSheetReturnCode($panel, NSModalResponseCancel);
    pumpFor(0.3);

    expect(dialogMail())->toBe(['files:']);
});

it('sets an open panel for folders', function (): void {
    [$main, $id, $panel] = withSheet(fn ($main) => driver()->dialogs()->openFiles($main, new OpenRequest(folders: true)));

    expect($panel->canChooseFiles())->toBeFalse()
        ->and($panel->canChooseDirectories())->toBeTrue()
        ->and($panel->allowsMultipleSelection())->toBeFalse();
    $main->close();
});

it('answers a save panel with the folder and the name', function (): void {
    [$main, $id, $panel] = withSheet(fn ($main) => driver()->dialogs()->saveFile($main, new SaveRequest(null, __DIR__, 'c7-save.txt')));

    expect($panel)->toBeInstanceOf(NSSavePanel::class)
        ->and($panel->nameFieldStringValue())->toBe('c7-save.txt');

    $main->native()->endSheetReturnCode($panel, NSModalResponseOK);
    pumpFor(0.3);

    expect(dialogMail())->toBe(['files:' . realpath(__DIR__) . '/c7-save.txt']);
});

it('shows a panel for a missing directory', function (): void {
    [$main, $id, $panel] = withSheet(fn ($main) => driver()->dialogs()->openFiles($main, new OpenRequest(directory: '/nonexistent-c7')));

    expect($panel)->toBeInstanceOf(NSOpenPanel::class);
    $main->close();
});

it('shows a styled alert with a suppression box and answers whether it was ticked', function (bool $ticked): void {
    [$main, $id, $sheet] = withSheet(fn ($main) => driver()->dialogs()->alert($main, new Alert('Delete?', null, ['Delete', 'Cancel'], style: AlertStyle::Warning, suppression: 'Don\'t ask again')));
    $alert = driver()->dialogs()->native($id);
    $box = $alert->suppressionButton();

    expect($alert->alertStyle())->toBe(NSAlertStyle::WARNING)
        ->and($alert->showsSuppressionButton())->toBeTrue()
        ->and($box->title())->toBe('Don\'t ask again');

    $box->setState($ticked ? NSControlStateValue::ON : NSControlStateValue::OFF);
    $main->native()->endSheetReturnCode($sheet, NSAlertFirstButtonReturn);
    pumpFor(0.3);

    expect(dialogMail())->toBe([$ticked ? 'alert:0:suppressed' : 'alert:0']);
})->with(['ticked' => true, 'not ticked' => false]);

it('maps each alert style to NSAlert\'s', function (?AlertStyle $style, NSAlertStyle $native): void {
    [$main, $id] = withSheet(fn ($main) => driver()->dialogs()->alert($main, new Alert('Hi', style: $style)));

    expect(driver()->dialogs()->native($id)->alertStyle())->toBe($native)
        ->and(driver()->dialogs()->native($id)->showsSuppressionButton())->toBeFalse();
    $main->close();
})->with([
    'plain' => [null, NSAlertStyle::WARNING],
    'info' => [AlertStyle::Info, NSAlertStyle::INFORMATIONAL],
    'warning' => [AlertStyle::Warning, NSAlertStyle::WARNING],
    'critical' => [AlertStyle::Critical, NSAlertStyle::CRITICAL],
]);

it('has no alert titles and shows hidden files', function (): void {
    $main = driver()->open('main', 480, 320);

    expect(driver()->dialogs()->supports(DialogCapability::AlertTitle))->toBeFalse()
        ->and(driver()->dialogs()->supports(DialogCapability::ShowHidden))->toBeTrue()
        ->and(fn () => driver()->dialogs()->alert($main, new Alert('Hi', title: 'Greeting')))->toThrow(WindowException::class, 'title')
        ->and(driver()->dialogs()->openOn($main))->toBeNull();
});

it('answers a styled alert -1 and not suppressed when the parent closes', function (): void {
    [$main, $id] = withSheet(fn ($main) => driver()->dialogs()->alert($main, new Alert('Delete?', style: AlertStyle::Critical, suppression: 'Don\'t ask again')));
    driver()->dialogs()->native($id)->suppressionButton()->setState(NSControlStateValue::ON);
    $main->close();
    pumpFor(0.6);

    expect(dialogMail())->toBe(['alert:-1', 'closed:main']);
});

it('allows the types of the filters\' extensions and shows hidden files', function (string $kind): void {
    $request = ['filters' => ['Text' => ['*.txt', '*.md'], 'Archives' => ['*.tar.gz']], 'showHidden' => true];
    [$main, $id, $panel] = withSheet(fn ($main) => $kind === 'open'
        ? driver()->dialogs()->openFiles($main, new OpenRequest(...$request))
        : driver()->dialogs()->saveFile($main, new SaveRequest(...$request)));

    expect(array_map(fn (UTType $type): string => $type->identifier(), $panel->allowedContentTypes()))->toBe([
        'public.plain-text',
        UTType::typeWithFilenameExtension('md')->identifier(),
        UTType::typeWithFilenameExtension('gz')->identifier(),
    ])
        ->and($panel->showsHiddenFiles())->toBeTrue();
    $main->close();
})->with(['open', 'save']);

it('allows every type for a catch-all pattern', function (string $all): void {
    [$main, $id, $panel] = withSheet(fn ($main) => driver()->dialogs()->openFiles($main, new OpenRequest(filters: ['Text' => ['*.txt'], 'All files' => [$all]])));

    expect($panel->allowedContentTypes())->toBe([]);
    $main->close();
})->with(['*', '*.*']);

it('refuses a pattern that is not a catch-all or one extension', function (string $pattern): void {
    $main = driver()->open('main', 480, 320);

    expect(fn () => driver()->dialogs()->openFiles($main, new OpenRequest(filters: ['C' => [$pattern]])))->toThrow(InvalidArgumentException::class, $pattern)
        ->and(driver()->dialogs()->openOn($main))->toBeNull();
})->with(['*.[ch]', '*.{c,h}', '*.']);

it('refuses a filter pattern with no extension, and the parent shows the next request', function (): void {
    $main = driver()->open('main', 480, 320);
    $main->present();

    expect(fn () => driver()->dialogs()->openFiles($main, new OpenRequest(filters: ['Make' => ['Makefile']])))->toThrow(InvalidArgumentException::class, 'Makefile')
        ->and(driver()->dialogs()->openOn($main))->toBeNull()
        ->and($main->native()->attachedSheet())->toBeNull();

    $id = driver()->dialogs()->openFiles($main, new OpenRequest());
    pumpUntil(fn (): bool => ! is_null($main->native()->attachedSheet()), 2.0);
    expect(driver()->dialogs()->openOn($main))->toBe($id);
    $main->close();
});

it('answers [] when the parent of a panel closes, then closes it, and nothing more', function (): void {
    [$main] = withSheet(fn ($main) => driver()->dialogs()->openFiles($main, new OpenRequest()));
    $main->close();
    pumpFor(0.6);

    expect(dialogMail())->toBe(['files:', 'closed:main']);
});
