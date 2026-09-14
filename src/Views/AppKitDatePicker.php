<?php

namespace Jovian\Venusian\AppKit\Views;

use Jovian\Bindings\AppKit\NS\NSControl;
use Jovian\Bindings\AppKit\NS\NSDateFormatter;
use Jovian\Bindings\AppKit\NS\NSDatePicker;
use Jovian\Bindings\AppKit\Runtime\Bridge;
use Jovian\Bindings\AppKit\Runtime\ObjCObject;
use Surface\Contracts\NativeWindows\Views\Color;
use Surface\NativeWindows\Views\DatePicker;
use Surface\NativeWindows\Windowable;

/**
 * A Surface day picker over NSDatePicker (clock-and-calendar,
 * year-month-day, single). NSDate crosses the seam as Y-m-d through a
 * held NSDateFormatter in the formatter's default time zone.
 * setDateValue: can send the control action, so applying keeps
 * Surface's own setDate() from echoing back as mail.
 */
class AppKitDatePicker extends DatePicker
{
    use TranslatesAppKitFrames;

    protected bool $applying = false;

    public function __construct(
        string $name,
        Windowable $window,
        ?string $date,
        public readonly NSDatePicker $picker,
        public readonly NSDateFormatter $formatter,
    ) {
        parent::__construct($name, $window, $date);

        if (! is_null($this->year) && ! is_null($this->month) && ! is_null($this->day)) {
            $this->writeNative($this->year, $this->month, $this->day);
        }

        Bridge::setAction(
            $picker->handle,
            function (ObjCObject $sender): void {
                if ($this->applying) {
                    return;
                }
                $ymd = $this->readNative();
                if (is_null($ymd)) {
                    return;
                }
                [$year, $month, $day] = $ymd;
                $this->fireChanged($year, $month, $day);
            },
        );
    }

    protected function control(): NSControl
    {
        return $this->picker;
    }

    protected function applyDate(int $year, int $month, int $day): void
    {
        $this->applying = true;
        $this->writeNative($year, $month, $day);
        $this->applying = false;
    }

    protected function writeNative(int $year, int $month, int $day): void
    {
        $ymd = sprintf('%04d-%02d-%02d', $year, $month, $day);
        $date = $this->formatter->dateFromString($ymd);
        if ($date instanceof ObjCObject) {
            $this->picker->setDateValue($date->handle);
        }
    }

    /**
     * @return array{int, int, int}|null
     */
    public function readNative(): ?array
    {
        $date = $this->picker->dateValue();
        if (! $date instanceof ObjCObject) {
            return null;
        }

        $ymd = $this->formatter->stringFromDate($date->handle);
        if (! is_string($ymd) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $ymd, $parts) !== 1) {
            return null;
        }

        return [(int) $parts[1], (int) $parts[2], (int) $parts[3]];
    }

    protected function applyEnabled(bool $enabled): void
    {
        $this->picker->setEnabled($enabled);
    }

    protected function applyBackground(Color $color): void {}
}
