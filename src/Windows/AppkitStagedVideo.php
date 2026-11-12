<?php

namespace Jovian\Toolkits\Appkit\Windows;

use AVPlayerView;
use AVPlayerViewControlsStyle;
use Jovian\Toolkits\Appkit\Primitives\Concerns\AppkitPlayer;
use NSRect;
use NSView;
use Surface\Windows\NativeVideo;

/**
 * A staged window's native video: the AVPlayerView a toolkit window's AppkitVideo is (inline
 * controls, AppkitPlayer behind it), inside a plain view that clips it, as the last subview of
 * the view the window draws in. AppKit composites it over that view's own layer, a lent
 * CAMetalLayer, the GL view or SDL's swapchain view alike, so it shows over every engine.
 *
 * The clip view's frame is the part that shows; the player view keeps the whole box and is
 * offset inside it. Both are placed in the drawing view's coordinates (y up), so the window
 * places its videos again when it resizes (relayout()). The clip view goes back to the top
 * whenever a surface lent later has put a view above it.
 */
class AppkitStagedVideo extends NativeVideo
{
    use AppkitPlayer;

    /** What shows of the player: its frame is the visible part, and it cuts the rest off. */
    protected NSView $clip;

    protected AVPlayerView $view;

    public function __construct(string $name, protected readonly AppkitStagedWindow $stage, ?string $file)
    {
        parent::__construct($name, $stage, $file);
        $this->clip = NSView::initWithFrame(new NSRect(0.0, 0.0, 0.0, 0.0));
        $this->clip->setWantsLayer(true);
        $this->clip->layer()?->setMasksToBounds(true);
        $this->clip->setHidden(true);
        $this->view = AVPlayerView::initWithFrame(new NSRect(0.0, 0.0, 0.0, 0.0));
        $this->view->setControlsStyle(AVPlayerViewControlsStyle::INLINE);
        $this->clip->addSubview($this->view);
        $this->startPlayer($this->view);
        $this->applyFile($file);
    }

    /** The player view, for tests. */
    public function native(): AVPlayerView
    {
        return $this->view;
    }

    /** The view that clips the player, for tests: hidden until placed, then a subview of the window's drawing view. */
    public function clipView(): NSView
    {
        return $this->clip;
    }

    /** The drawing view has a new size: the same placement is at other coordinates in it. */
    public function relayout(): void
    {
        if (! $this->removed && ! is_null($this->frame) && ! is_null($this->visible)) {
            $this->applyFrame($this->frame, $this->visible);
        }
    }

    protected function applyFrame(array $frame, array $visible): void
    {
        $host = $this->stage->view();
        $above = $host->subviews();
        if ($above === [] || end($above)->pointer() !== $this->clip->pointer()) {
            $this->clip->removeFromSuperview();
            $host->addSubview($this->clip);
        }
        [$x, $y, $width, $height] = $frame;
        [$left, $top, $across, $down] = $visible;
        // Points from the top-left become AppKit's, y up: the clip in the drawing view, the player in the clip.
        $this->clip->setFrame(new NSRect($left, $host->bounds()->height - $top - $down, $across, $down));
        $this->view->setFrame(new NSRect($x - $left, ($top + $down) - ($y + $height), $width, $height));
        $this->clip->setHidden(false);
    }

    protected function applyHidden(): void
    {
        $this->clip->setHidden(true);
    }

    protected function releaseNative(): void
    {
        $this->stopPlayer($this->view);
        $this->clip->removeFromSuperview();
    }
}
