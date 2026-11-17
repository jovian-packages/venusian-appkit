<?php

namespace Jovian\Toolkits\Appkit\Primitives;

use CADisplayLink;
use CFData;
use CGColorSpace;
use CGDataProvider;
use CGImage;
use Closure;
use Jovian\Toolkits\Appkit\Input\AppkitInputChannel;
use Jovian\Toolkits\Appkit\Input\Seen;
use Jovian\Toolkits\Appkit\Input\SeenKind;
use Jovian\Toolkits\Appkit\Primitives\Concerns\AppkitPrimitive;
use NSEvent;
use NSRect;
use NSRunLoop;
use ObjCFocusView;
use ObjCTarget;
use WeakReference;
use Surface\Contracts\Graphics\HostFormatStage;
use Surface\Contracts\Graphics\PacedStage;
use Surface\Contracts\Rendering\Framebuffers\FormatSpec;
use Surface\Contracts\Toolkits\Primitives\Placement;
use Surface\Contracts\Toolkits\ToolkitRuntimeException;
use Surface\Contracts\Toolkits\ToolkitWindow;
use Surface\Toolkits\Primitives\TKCanvas;
use Surface\Toolkits\Primitives\TKPrimitiveGroup;

/**
 * A layer-backed view whose layer contents are the pixels it was last given: each display() wraps
 * the bytes in a CGImage (sRGB, the alpha byte skipped, so opaque) and sets it as the contents,
 * stretched over the bounds. The view has no natural size: the layout sizes it.
 *
 * Once its input is open (input()), the view accepts first responder, so a click or focus() gives
 * it the keyboard: it takes keyDown:, keyUp: and flagsChanged: for its input and hands key downs to
 * interpretKeyEvents:, whose insertText: is the text typed and whose commands it swallows, so no
 * key it takes beeps. Its pointer comes from the input backend. Before its input is open the view
 * takes neither focus nor keys. It lends its view to an engine that draws on macOS itself.
 *
 * It is a paced stage: a display link on the view fires once a refresh of the display the view is
 * on, and what was asked to be shown is shown then. With nothing asked the link is paused, so an
 * idle canvas wakes nothing.
 */
class AppkitCanvas extends TKCanvas implements PacedStage, HostFormatStage
{
    use AppkitPrimitive;

    protected static ?CGColorSpace $space = null;

    /** What to show at the next refresh; null: nothing asked. */
    protected ?Closure $due = null;

    /** Fires once a refresh while something is due: made the first time something is. */
    protected ?CADisplayLink $link = null;

    /** The display link's target: it must outlive the link. */
    protected ?ObjCTarget $ticker = null;

    public function __construct(string $name, ToolkitWindow $window, ?TKPrimitiveGroup $parent, Placement $placement)
    {
        parent::__construct($name, $window, $parent, $placement);
        $view = $this->adoptNative(ObjCFocusView::initWithFrame(new NSRect(0.0, 0.0, 0.0, 0.0)));
        $view->setWantsLayer(true);
        $view->layer()?->setMasksToBounds(true);
        $view->layer()?->setContentsGravity(kCAGravityResize);

        // The view holds the handlers, and this canvas the view: a weak reference breaks the cycle.
        $self = WeakReference::create($this);
        $view->on('acceptsFirstResponder', static fn (): bool => ! is_null($self->get()?->keyboardInput()));
        $view->on('keyDown:', static fn (NSEvent $event): bool => $self->get()?->keyed($event, true) ?? false);
        $view->on('keyUp:', static fn (NSEvent $event): bool => $self->get()?->keyed($event, false) ?? false);
        $view->on('flagsChanged:', static fn (NSEvent $event): bool => $self->get()?->flagged($event) ?? false);
        $view->on('insertText:replacementRange:', static fn (string $text): bool => $self->get()?->typed($text) ?? false);
        // a command a key asks for (an arrow's moveLeft:, Return's insertNewline:) is the key, already read
        $view->on('doCommandBySelector:', static fn (string $selector): bool => ! is_null($self->get()?->keyboardInput()));
    }

    /** The input that takes this canvas's keys: open, and from the appkit backend. */
    public function keyboardInput(): ?AppkitInputChannel
    {
        $input = $this->openInput();

        return $input instanceof AppkitInputChannel ? $input : null;
    }

    /** @internal keyDown: and keyUp: while first responder */
    public function keyed(NSEvent $event, bool $down): bool
    {
        $input = $this->keyboardInput();
        if (is_null($input)) {
            return false;
        }
        $input->see(new Seen(SeenKind::Key, $input, flags: $event->modifierFlags(), code: $event->keyCode(), down: $down, repeat: $down && $event->isARepeat()));
        if ($down) {
            $this->native->interpretKeyEvents([$event]);
        }

        return true;
    }

    /** @internal flagsChanged: while first responder */
    public function flagged(NSEvent $event): bool
    {
        $input = $this->keyboardInput();
        if (is_null($input)) {
            return false;
        }
        $input->see(new Seen(SeenKind::Flags, $input, flags: $event->modifierFlags(), code: $event->keyCode()));

        return true;
    }

    /** @internal insertText: from interpretKeyEvents: */
    public function typed(string $text): bool
    {
        $input = $this->keyboardInput();
        if (is_null($input)) {
            return false;
        }
        $input->see(new Seen(SeenKind::Text, $input, text: $text));

        return true;
    }

    public function native(): ObjCFocusView
    {
        return $this->native;
    }

    /** Its view: an engine that draws on macOS itself puts what it draws with over it. */
    public function nativeSurface(): ?object
    {
        return $this->native;
    }

    public function whenDue(Closure $show): void
    {
        $this->live();
        $this->due = $show;

        if (is_null($this->link)) {
            $this->ticker = new ObjCTarget(fn () => $this->refreshed());
            $this->link = $this->native->displayLinkWithTargetSelector($this->ticker, 'action:');
            // common modes: frames go on showing while a menu is open or the window is being resized
            $this->link->addToRunLoopForMode(NSRunLoop::mainRunLoop(), NSRunLoopCommonModes);
        }
        $this->link->setPaused(false);
    }

    /** The display is due a frame: show what was asked, or with nothing asked stop being woken. */
    protected function refreshed(): void
    {
        if (is_null($this->due)) {
            $this->link?->setPaused(true);

            return;
        }

        [$show, $this->due] = [$this->due, null];
        $show();
    }

    public function remove(): void
    {
        foreach (['acceptsFirstResponder', 'keyDown:', 'keyUp:', 'flagsChanged:', 'insertText:replacementRange:', 'doCommandBySelector:'] as $selector) {
            $this->native->off($selector);
        }
        $this->due = null;
        $this->link?->invalidate();
        [$this->link, $this->ticker] = [null, null];

        parent::remove();
    }

    /** The window's backing scale: 2.0 on a Retina display. */
    protected function nativeScale(): float
    {
        return $this->host()->native()->backingScaleFactor();
    }

    /** Core Graphics takes either byte order as it is: R G B X, or B G R X. */
    public function hostFormats(): array
    {
        return [FormatSpec::rgba8(), FormatSpec::bgra8()];
    }

    /** @throws ToolkitRuntimeException When the bytes are not $width x $height pixels, or $format is not one of hostFormats(). */
    public function displayPixels(string $pixels, int $width, int $height, FormatSpec $format): void
    {
        $this->live();
        if ($width < 1 || $height < 1 || strlen($pixels) !== $width * $height * 4) {
            throw new ToolkitRuntimeException("Canvas '{$this->path()}' was given ".strlen($pixels)." bytes for {$width}x{$height} pixels: four bytes a pixel takes ".($width * $height * 4).'.');
        }
        match (true) {
            $format->equals(FormatSpec::rgba8()) => $this->applyPixels($pixels, $width, $height),
            // B G R X read as a little-endian word, the alpha byte first and skipped
            $format->equals(FormatSpec::bgra8()) => $this->showImage($pixels, $width, $height, kCGImageAlphaNoneSkipFirst | kCGBitmapByteOrder32Little),
            default => throw new ToolkitRuntimeException("Canvas '{$this->path()}' shows RGBA8 and BGRA8 pixels as they are, and was given another format."),
        };
    }

    /** @throws ToolkitRuntimeException When Core Foundation makes no data or Core Graphics refuses the image. */
    protected function applyPixels(string $rgba8, int $width, int $height): void
    {
        // R G B X read as a big-endian word: the bytes in the order they were given
        $this->showImage($rgba8, $width, $height, kCGImageAlphaNoneSkipLast | kCGBitmapByteOrder32Big);
    }

    /**
     * The bytes as the layer's contents, read as $bitmapInfo says.
     *
     * @throws ToolkitRuntimeException When Core Foundation makes no data or Core Graphics refuses the image.
     */
    private function showImage(string $pixels, int $width, int $height, int $bitmapInfo): void
    {
        self::$space ??= CGColorSpace::createWithName(kCGColorSpaceSRGB);
        $data = CFData::create(null, $pixels, strlen($pixels));
        $provider = is_null($data) ? null : CGDataProvider::createWithCFData($data);
        $image = is_null($provider) ? null : CGImage::create(
            $width, $height, 8, 32, $width * 4, self::$space,
            $bitmapInfo, $provider, null, true, kCGRenderingIntentDefault,
        );
        if (is_null($image)) {
            throw new ToolkitRuntimeException("Canvas '{$this->path()}' could not show a {$width}x{$height} image.");
        }
        $this->native->layer()?->setContents($image);
    }
}
