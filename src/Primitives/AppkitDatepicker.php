<?php

namespace Jovian\Toolkits\Appkit\Primitives;

use DateTimeImmutable;
use DateTimeZone;
use Jovian\Toolkits\Appkit\Primitives\Concerns\AppkitPrimitive;
use NSDate;
use NSDatePicker;
use NSDatePickerMode;
use NSDatePickerStyle;
use NSRect;
use NSTimeZone;
use ObjCTarget;
use Surface\Contracts\Windows\Mail\View\DateChanged;
use Surface\Contracts\Windows\Primitives\Placement;
use Surface\Contracts\Windows\ToolkitWindow;
use Surface\Windows\Primitives\TKDatepicker;
use Surface\Windows\Primitives\TKPrimitiveGroup;

/**
 * A text-field-and-stepper NSDatePicker showing year, month and day in PHP's default time
 * zone, so the day the user sees is the day PHP reads: a date is midnight of its day in that
 * zone both ways. A null date shows today; date() stays null until a day is picked or set.
 */
class AppkitDatepicker extends TKDatepicker
{
    use AppkitPrimitive;

    /**
     * Controls hold targets weakly: the primitive keeps it.
     */
    protected ObjCTarget $target;

    protected readonly DateTimeZone $zone;

    public function __construct(string $name, ToolkitWindow $window, ?TKPrimitiveGroup $parent, Placement $placement, ?DateTimeImmutable $date)
    {
        parent::__construct($name, $window, $parent, $placement, $date);
        $this->zone = new DateTimeZone(date_default_timezone_get());
        $this->target = new ObjCTarget(fn () => $this->picked());

        $picker = $this->adoptNative(NSDatePicker::initWithFrame(new NSRect()));
        $picker->setDatePickerStyle(NSDatePickerStyle::TEXT_FIELD_AND_STEPPER);
        $picker->setDatePickerElements(NSDatePicker::ELEMENT_YEAR_MONTH_DAY);
        $picker->setDatePickerMode(NSDatePickerMode::SINGLE);
        $picker->setTimeZone(NSTimeZone::timeZoneWithName($this->zone->getName()));
        $picker->setTarget($this->target);
        $picker->setAction(ObjCTarget::ACTION);
        $this->applyDate($date);
    }

    public function native(): NSDatePicker
    {
        return $this->native;
    }

    protected function applyDate(?DateTimeImmutable $date): void
    {
        $day = new DateTimeImmutable(($date ?? new DateTimeImmutable('now', $this->zone))->format('Y-m-d'), $this->zone);
        $this->native->setDateValue(NSDate::dateWithTimeIntervalSince1970((float) $day->getTimestamp()));
    }

    protected function releaseNative(): void
    {
        $this->native->setTarget(null);
    }

    protected function picked(): void
    {
        $seconds = (int) floor($this->native->dateValue()->timeIntervalSince1970());
        $this->nativeDateChanged((new DateTimeImmutable("@{$seconds}"))->setTimezone($this->zone)->setTime(0, 0));
        $this->session()->post(new DateChanged($this->window->name(), $this->path(), $this->uuid, $this->date));
    }
}
