<?php

namespace Jovian\Toolkits\Appkit\Primitives;

use Jovian\Toolkits\Appkit\Primitives\Concerns\AppkitPrimitive;
use NSRect;
use NSSegmentedControl;
use NSSegmentSwitchTracking;
use ObjCTarget;
use Surface\Contracts\Windows\Mail\View\SelectionChanged;
use Surface\Contracts\Windows\Primitives\Placement;
use Surface\Contracts\Windows\ToolkitWindow;
use Surface\Windows\Primitives\TKPrimitiveGroup;
use Surface\Windows\Primitives\TKSegmentedControl;
use WeakReference;

/**
 * An NSSegmentedControl tracking select-one. Its action posts SelectionChanged.
 */
class AppkitSegmentedControl extends TKSegmentedControl
{
    use AppkitPrimitive;

    /**
     * Controls hold targets weakly: the primitive keeps it.
     */
    protected ObjCTarget $target;

    public function __construct(string $name, ToolkitWindow $window, ?TKPrimitiveGroup $parent, Placement $placement, array $segments, int $selected)
    {
        parent::__construct($name, $window, $parent, $placement, $segments, $selected);
        // The target holds its callable where PHP's collector cannot see it: a weak reference keeps it from holding the primitive.
        $self = WeakReference::create($this);
        $this->target = new ObjCTarget(static fn () => $self->get()?->chosen());
        $control = $this->adoptNative(NSSegmentedControl::initWithFrame(new NSRect(0.0, 0.0, 0.0, 0.0)));
        $control->setTrackingMode(NSSegmentSwitchTracking::SELECT_ONE);
        $control->setTarget($this->target);
        $control->setAction(ObjCTarget::ACTION);
        $this->applySegments($this->segments);
        $this->applySelected($this->selected);
    }

    public function native(): NSSegmentedControl
    {
        return $this->native;
    }

    protected function applySegments(array $segments): void
    {
        $this->native->setSegmentCount(count($segments));
        foreach ($segments as $index => $label) {
            $this->native->setLabelForSegment($label, $index);
        }
        $this->remeasure();
    }

    protected function applySegment(int $index, string $label): void
    {
        $this->native->setLabelForSegment($label, $index);
        $this->remeasure();
    }

    protected function applySelected(int $index): void
    {
        $this->native->setSelectedSegment($index);
    }

    protected function releaseNative(): void
    {
        $this->native->setTarget(null);
    }

    protected function chosen(): void
    {
        $index = $this->native->selectedSegment();
        if ($index < 0 || $index === $this->selected) {
            return;
        }
        $this->nativeSelected($index);
        $this->session()->post(new SelectionChanged($this->window->name(), $this->path(), $this->uuid, $index, $this->segments[$index]));
    }
}
