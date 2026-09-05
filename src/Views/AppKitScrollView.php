<?php

namespace Jovian\Venusian\AppKit\Views;

use Jovian\Bindings\AppKit\NS\NSClipView;
use Jovian\Bindings\AppKit\NS\NSScrollView;
use Jovian\Bindings\AppKit\NS\NSView;
use Jovian\Bindings\AppKit\QuartzCore\CALayer;
use Jovian\Bindings\AppKit\Values\NSPoint;
use Jovian\Bindings\AppKit\Values\NSRect;
use Jovian\Bindings\AppKit\Values\NSSize;
use Surface\Contracts\NativeWindows\Views\Color;
use Surface\NativeWindows\Views\ScrollView;
use Surface\NativeWindows\Windowable;

/**
 * A Surface scroll view over an NSScrollView whose document is a plain
 * NSView sized to the content extent. Children mint into the document, so
 * AppKit scrolls the subtree; their frames invert against the EXTENT
 * height through layoutSpace(), which is what makes 0,0 the top-left of
 * the scrollable content on both engines.
 *
 * The document is not flipped (the ext cannot subclass NSView), so an
 * unscrolled NSScrollView shows the document's BOTTOM. Every extent write
 * re-pins the viewport to the top to keep Surface's promise.
 */
class AppKitScrollView extends ScrollView implements HostsAppKitChildren
{
    use ComposesAppKitStyle;

    public function __construct(
        string $name,
        Windowable $window,
        public readonly NSScrollView $scroll,
        public readonly NSView $document,
    ) {
        parent::__construct($name, $window);
    }

    public function childSurface(): NSView
    {
        return $this->document;
    }

    protected function applyContentSize(int $width, int $height): void
    {
        $this->document->setFrameSize(new NSSize((float) $width, (float) $height));
        $this->pinToTop();
    }

    /**
     * Scroll the clip so the document's top is visible — the top in
     * bottom-left coordinates is extent height minus viewport height.
     */
    protected function pinToTop(): void
    {
        [, $extent_height] = $this->contentExtent();
        $clip = $this->scroll->contentView();
        if (! $clip instanceof NSClipView) {
            return;
        }

        $clip->scrollToPoint(new NSPoint(0.0, (float) max(0, $extent_height - $this->height)));
        $this->scroll->reflectScrolledClipView($clip->handle);
    }

    protected function applyFrame(int $x, int $y, int $width, int $height): void
    {
        [, $content_height] = $this->layoutSpace();

        $this->scroll->setFrame(new NSRect(
            (float) $x,
            (float) ($content_height - $y - $height),
            (float) $width,
            (float) $height,
        ));

        // With no explicit extent the document tracks the viewport; with
        // one, the viewport just changed size and the top moved.
        if (is_null($this->content_width) && is_null($this->content_height)) {
            $this->document->setFrameSize(new NSSize((float) $width, (float) $height));
        } else {
            $this->pinToTop();
        }
    }

    /** A viewport has no natural size worth trusting; hug keeps the frame. */
    protected function measure(): array
    {
        return [$this->width, $this->height];
    }

    protected function destroyNative(): void
    {
        $this->scroll->removeFromSuperview();
    }

    protected function applyVisible(bool $visible): void
    {
        $this->scroll->setHidden(! $visible);
    }

    protected function applyBackground(Color $color): void
    {
        $this->document->setWantsLayer(true);
        $layer = $this->document->layer();
        if ($layer instanceof CALayer) {
            $ns_color = $this->nsColor($color);
            $layer->setBackgroundColor($ns_color->CGColor());
        }
    }
}
