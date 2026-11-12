<?php

namespace Jovian\Toolkits\Appkit\Primitives;

use AVPlayerView;
use AVPlayerViewControlsStyle;
use Jovian\Toolkits\Appkit\Primitives\Concerns\AppkitPlayer;
use Jovian\Toolkits\Appkit\Primitives\Concerns\AppkitPrimitive;
use NSRect;
use Surface\Contracts\Windows\Primitives\Placement;
use Surface\Contracts\Windows\ToolkitWindow;
use Surface\Windows\Primitives\TKPrimitiveGroup;
use Surface\Windows\Primitives\TKVideo;

/**
 * An AVPlayerView (inline controls) over one AVPlayer (AppkitPlayer plays it and posts its
 * mail). Disabled, the view shows no controls.
 */
class AppkitVideo extends TKVideo
{
    use AppkitPrimitive;
    use AppkitPlayer;

    public function __construct(string $name, ToolkitWindow $window, ?TKPrimitiveGroup $parent, Placement $placement, ?string $file)
    {
        parent::__construct($name, $window, $parent, $placement, $file);
        $view = $this->adoptNative(AVPlayerView::initWithFrame(new NSRect(0.0, 0.0, 0.0, 0.0)));
        $view->setControlsStyle(AVPlayerViewControlsStyle::INLINE);
        $this->startPlayer($view);
        $this->applyFile($file);
    }

    public function native(): AVPlayerView
    {
        return $this->native;
    }

    protected function applyNativeEnabled(bool $on): void
    {
        $this->native->setControlsStyle($on ? AVPlayerViewControlsStyle::INLINE : AVPlayerViewControlsStyle::NONE);
    }

    protected function releaseNative(): void
    {
        $this->stopPlayer($this->native);
    }

    protected function post(object $mail): void
    {
        $this->session()->post($mail);
    }
}
