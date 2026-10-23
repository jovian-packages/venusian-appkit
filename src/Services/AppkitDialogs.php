<?php

namespace Jovian\Toolkits\Appkit\Services;

use Closure;
use Jovian\Toolkits\Appkit\Bridge\AppkitBridgeDriver;
use Jovian\Toolkits\Appkit\Bridge\AppkitSession;
use Jovian\Toolkits\Appkit\Windows\AppkitWindow;
use NSAlert;
use NSOpenPanel;
use NSSavePanel;
use NSURL;
use NSWindow;
use Surface\Contracts\Windows\Services\Alert;
use Surface\Contracts\Windows\Services\OpenRequest;
use Surface\Contracts\Windows\Services\SaveRequest;
use Surface\Contracts\Windows\ToolkitWindow;
use Surface\Windows\Services\ToolkitDialogs;
use Voyager\Contracts\Signals\NamedSignal;
use WeakReference;

/**
 * Alerts and file panels as sheets on their parent. The completion block answers; the parent's
 * close ends the sheet with NSModalResponseCancel, whose late completion finds the id gone.
 */
final class AppkitDialogs extends ToolkitDialogs
{
    /**
     * The open sheets by request id: the native, the parent's window that holds the sheet, the
     * sheet's own window, and the tap that answers Esc for a one-role alert.
     * @var array<string, array{native: NSAlert|NSSavePanel, parent: NSWindow, sheet: NSWindow, tap: ?AppkitAlertEscape}>
     */
    private array $sheets = [];

    public function __construct(AppkitBridgeDriver $driver, private readonly AppkitSession $session)
    {
        parent::__construct($driver);
    }

    protected function showAlert(string $id, ToolkitWindow $parent, Alert $alert): void
    {
        $native = NSAlert::init();
        $native->setMessageText($alert->message);
        $native->setInformativeText($alert->detail ?? '');
        foreach ($alert->buttons as $index => $label) {
            $button = $native->addButtonWithTitle($label);
            // AppKit gives Return to the first button; the roles say which button takes Return and Esc.
            $button->setKeyEquivalent(match (true) {
                $index === $alert->default => "\r",
                $index === $alert->cancel => "\e",
                default => '',
            });
        }
        $holder = $this->parentWindow($parent);
        $weak = WeakReference::create($this);
        $native->beginSheetModalForWindowCompletionHandler($holder, static function (int $code) use ($weak, $id): void {
            $weak->get()?->ended($id, fn (): int => $code - NSAlertFirstButtonReturn);
        });
        $sheet = $native->window();
        // One button holds one key equivalent: when the default is also the cancel, a tap answers Esc.
        $tap = $alert->default === $alert->cancel
            ? new AppkitAlertEscape($this->session, $sheet, static function () use ($holder, $sheet, $alert): void {
                $holder->endSheetReturnCode($sheet, NSAlertFirstButtonReturn + $alert->cancel);
            })
            : null;
        $this->sheets[$id] = ['native' => $native, 'parent' => $holder, 'sheet' => $sheet, 'tap' => $tap];
    }

    protected function showOpen(string $id, ToolkitWindow $parent, OpenRequest $request): void
    {
        $panel = NSOpenPanel::openPanel();
        $panel->setCanChooseFiles(! $request->folders);
        $panel->setCanChooseDirectories($request->folders);
        $panel->setAllowsMultipleSelection($request->multiple);
        $this->begin($id, $parent, $panel, $request->title, $request->directory, static fn (NSOpenPanel $panel): array => array_map(
            static fn (NSURL $url): ?string => $url->path(),
            $panel->URLs(),
        ));
    }

    protected function showSave(string $id, ToolkitWindow $parent, SaveRequest $request): void
    {
        $panel = NSSavePanel::savePanel();
        if (! is_null($request->name)) {
            $panel->setNameFieldStringValue($request->name);
        }
        $this->begin($id, $parent, $panel, $request->title, $request->directory, static fn (NSSavePanel $panel): array => [$panel->URL()?->path()]);
    }

    protected function dismiss(string $id): void
    {
        $sheet = $this->release($id);
        if (! is_null($sheet)) {
            $sheet['parent']->endSheetReturnCode($sheet['sheet'], NSModalResponseCancel);
        }
    }

    protected function post(NamedSignal $mail): void
    {
        $this->session->post($mail);
    }

    /**
     * The sheet ended: let go of it, then answer. An id the parent's close already ended answers nothing.
     * @param Closure(): (int|list<string>) $answer
     */
    private function ended(string $id, Closure $answer): void
    {
        if (is_null($this->release($id))) {
            return;
        }
        $value = $answer();
        is_int($value) ? $this->answerAlert($id, $value) : $this->answerFiles($id, $value);
    }

    /**
     * @param Closure(NSSavePanel): list<?string> $chosen
     */
    private function begin(string $id, ToolkitWindow $parent, NSSavePanel $panel, ?string $title, ?string $directory, Closure $chosen): void
    {
        if (! is_null($title)) {
            $panel->setMessage($title);
        }
        $folder = self::folder($directory);
        if (! is_null($folder)) {
            $panel->setDirectoryURL(NSURL::fileURLWithPath($folder));
        }
        $holder = $this->parentWindow($parent);
        $weak = WeakReference::create($this);
        $panel->beginSheetModalForWindowCompletionHandler($holder, static function (int $result) use ($weak, $id, $panel, $chosen): void {
            $weak->get()?->ended($id, static fn (): array => $result === NSModalResponseOK
                ? array_values(array_filter($chosen($panel), static fn (?string $path): bool => ! is_null($path)))
                : []);
        });
        $this->sheets[$id] = ['native' => $panel, 'parent' => $holder, 'sheet' => $panel, 'tap' => null];
    }

    private function parentWindow(ToolkitWindow $parent): NSWindow
    {
        /** @var AppkitWindow $parent the parent check made it a window of this driver */
        return $parent->native();
    }

    /**
     * Forget the sheet of $id and detach its tap.
     * @return array{native: NSAlert|NSSavePanel, parent: NSWindow, sheet: NSWindow, tap: ?AppkitAlertEscape}|null null when it was gone
     */
    private function release(string $id): ?array
    {
        $sheet = $this->sheets[$id] ?? null;
        unset($this->sheets[$id]);
        if (! is_null($sheet)) {
            $sheet['tap']?->detach();
        }

        return $sheet;
    }
}
