<?php

namespace Jovian\Toolkits\Appkit\UI;

use NSRect;
use ObjCTarget;
use WeakReference;
use NSSegmentSwitchTracking;
use NSSegmentedControl as NativeSegments;
use Surface\Toolkits\UI\TKSegmentedControl;
use Jovian\Toolkits\Appkit\UI\Concerns\AppkitNative;
use Jovian\Toolkits\Appkit\UI\Concerns\TakesInputFromAbove;

/**
 * An NSSegmentedControl that holds one choice.
 */
class AppkitSegmentedControl extends TKSegmentedControl
{
    use AppkitNative {
        releaseNative as private releaseView;
    }
    use TakesInputFromAbove;

    /** Controls hold their targets weakly: the primitive keeps it. */
    private ?ObjCTarget $target = null;

    protected function bootstrapNative(): void
    {
        $self = WeakReference::create($this);
        $this->target = new ObjCTarget(static function () use ($self): void {
            $control = $self->get();
            $control?->chosen($control->segmentsNative()->selectedSegment());
        });

        $control = NativeSegments::initWithFrame(new NSRect(0.0, 0.0, 0.0, 0.0));
        $control->setTrackingMode(NSSegmentSwitchTracking::SELECT_ONE);
        $control->setTarget($this->target);
        $control->setAction(ObjCTarget::ACTION);
        $this->adoptNative($control);
        $this->applySegments($this->segments(), $this->selected());
    }

    protected function applySegments(array $segments, int $selected): void
    {
        $control = $this->segmentsNative();
        $control->setSegmentCount(count($segments));
        foreach ($segments as $index => $label) {
            $control->setLabelForSegment($label, $index);
        }
        if ($selected >= 0) {
            $control->setSelectedSegment($selected);
        }
    }

    protected function applySegment(int $index, string $label): void
    {
        $this->segmentsNative()->setLabelForSegment($label, $index);
    }

    protected function applySelected(int $index): void
    {
        $this->segmentsNative()->setSelectedSegment($index);
    }

    protected function applyNativeEnabled(bool $takes_input): void
    {
        $this->segmentsNative()->setEnabled($takes_input);
    }

    protected function releaseNative(): void
    {
        $this->segmentsNative()->setTarget(null);
        $this->target = null;
        $this->releaseView();
    }

    private function segmentsNative(): NativeSegments
    {
        /** @var NativeSegments */
        return $this->native();
    }
}
