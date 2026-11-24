<?php

namespace Jovian\Toolkits\Appkit\UI;

use NSDate;
use NSRect;
use ObjCTarget;
use NSTimeZone;
use DateTimeZone;
use WeakReference;
use NSDatePicker;
use DateTimeImmutable;
use NSDatePickerMode;
use NSDatePickerStyle;
use Surface\Toolkits\UI\TKDatepicker;
use Jovian\Toolkits\Appkit\UI\Concerns\AppkitNative;
use Jovian\Toolkits\Appkit\UI\Concerns\TakesInputFromAbove;

/**
 * An NSDatePicker as a field and stepper, the size of the other toolkits' pickers. It works
 * in PHP's default timezone, so the day the user sees is the day PHP reads.
 */
class AppkitDatepicker extends TKDatepicker
{
    use AppkitNative {
        releaseNative as private releaseView;
    }
    use TakesInputFromAbove;

    /** Controls hold their targets weakly: the primitive keeps it. */
    private ?ObjCTarget $target = null;

    private DateTimeZone $zone;

    protected function bootstrapNative(): void
    {
        $this->zone = new DateTimeZone(date_default_timezone_get());

        $picker = NSDatePicker::initWithFrame(new NSRect(0.0, 0.0, 0.0, 0.0));
        $picker->setDatePickerStyle(NSDatePickerStyle::TEXT_FIELD_AND_STEPPER);
        $picker->setDatePickerElements(NSDatePicker::ELEMENT_YEAR_MONTH_DAY);
        $picker->setDatePickerMode(NSDatePickerMode::SINGLE);
        $picker->setTimeZone(NSTimeZone::timeZoneWithName($this->zone->getName()));
        $this->adoptNative($picker);
        $this->applyDate($this->date());

        $self = WeakReference::create($this);
        $this->target = new ObjCTarget(static function () use ($self): void {
            $self->get()?->readPick();
        });
        $picker->setTarget($this->target);
        $picker->setAction(ObjCTarget::ACTION);
    }

    protected function applyDate(?DateTimeImmutable $date): void
    {
        $day = new DateTimeImmutable($this->shownDay()->format('Y-m-d').' 00:00:00', $this->zone);
        $this->pickerNative()->setDateValue(NSDate::dateWithTimeIntervalSince1970((float) $day->getTimestamp()));
    }

    protected function applyNativeEnabled(bool $takes_input): void
    {
        $this->pickerNative()->setEnabled($takes_input);
    }

    protected function releaseNative(): void
    {
        $this->pickerNative()->setTarget(null);
        $this->target = null;
        $this->releaseView();
    }

    private function readPick(): void
    {
        $seconds = (int) floor($this->pickerNative()->dateValue()->timeIntervalSince1970());
        $day = (new DateTimeImmutable("@{$seconds}"))->setTimezone($this->zone);

        $this->picked((int) $day->format('Y'), (int) $day->format('n'), (int) $day->format('j'));
    }

    private function pickerNative(): NSDatePicker
    {
        /** @var NSDatePicker */
        return $this->native();
    }
}
