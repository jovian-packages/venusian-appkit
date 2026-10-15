# jovian/venusian-appkit

AppKit toolkit driver for the Venusian Surface bridge.

## Canvas

`AppkitCanvas` shows a `TKCanvas`'s framebuffer as the contents of a layer-backed view: the RGBA8 bytes become a `CGImage` (sRGB, the fourth byte skipped, so opaque), stretched over the view's bounds.

An ext-fb framebuffer is piped. `present()` hands the framebuffer's memory to `CFData::create` by address, and Core Graphics copies what it needs. No pixel byte passes through PHP. A framebuffer held in PHP (the native driver) takes the same path from a string.

A GPU engine borrows the view's layer instead. With ext-metal loaded, `surfaces()` is `[METAL_LAYER]`: the canvas lends a `CAMetalLayer` (ext-metal) as the view's layer to the `metal` engine (jovian/venusian-metal), whose frames reach the window with no pixel through PHP. The `vulkan` engine (jovian/venusian-vulkan, with ext-vulkan) presents through the same layer: it makes a `VkSurfaceKHR` over it with `vkCreateMetalSurfaceEXT` (MoltenVK) and swaps into it; `renderer('vulkan', ['output' => $canvas])`. `reclaim()` restores a backing layer with gravity and clipping, and the canvas shows its own framebuffer again.

```php
$engine = app('drawing')->renderer('metal', ['output' => $canvas]);
$engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(16, 24, 32))->fillEllipse(160, 120, 40, 40, Color::rgb(255, 128, 0)));
$canvas->present();
```

With ext-sdl3 loaded as well, `surfaces()` is `[METAL_LAYER, SDL_WINDOW]`, and the `sdl3` engine (jovian/venusian-sdl3) draws into the canvas too. SDL wraps the canvas's window and leaves its layout alone; the swapchain view SDL_GPU makes is moved into the canvas and follows its size. The canvas starts SDL's video subsystem and never quits it. SDL's listener is taken back out of the window's responder chain, and the session gives the application an empty delegate at launch, so SDL never becomes it. After `reclaim()` the SDL window is destroyed by the session's next pump once the engine's device has let go of it.

```php
$engine = app('drawing')->renderer('sdl3', ['output' => $canvas]);
```

With ext-opengl loaded the canvas lends a GL context too, last in `surfaces()`: an `NSOpenGLView` (OpenGL 4.1 core, at the backing resolution) is pinned over the canvas, and the `opengl` engine (jovian/venusian-opengl) draws in its context. `present()` marks the view for display, and its `drawRect:` copies the engine's frame into the view on the GPU. The view has its context from the start, so the canvas lends before its window is shown.

```php
$engine = app('drawing')->renderer('opengl', ['output' => $canvas]);
```

## Staged windows

The driver is a stager too: `app('staged-windows')->open('game', 1280, 720, ['toolkit' => 'appkit', …])` makes an `AppkitStagedWindow`, a window whose whole content is one output. Its backend is `appkit/cocoa`.

```php
$window = app('staged-windows')->open('game', 640, 400, ['toolkit' => 'appkit', 'vsync' => VSync::Off]);
$window->setScaling(ScaleFilter::Nearest, ScaleFit::Integer);
$frame = $window->framebuffer('dirty', 320, 200);
$frame->setPixel(10, 10, 0xFF0000FF);
$window->present();                                   // only the damaged rects are redrawn
$window->setMode(WindowMode::Exclusive, $window->displayModes()[1]);
```

* Presenting: the frame is copied into a `CGImage`; only the damaged rects are marked for display, and `drawRect:` draws the image into `presentRect()` with the scaling filter. Vsync `On` leaves the draw to AppKit's display cycle; the others draw at once.
* Modes: `Fullscreen` is native full screen (its own Space, animated; a display passed moves the window there first). `Exclusive` captures the display, switches its mode, and covers it at the shielding level; leaving, or closing the window, puts the desktop mode back and releases the display. A mode asked for during a full-screen transition, or before the window is shown, is applied after.
* The window is an `ObjCStageWindow`, so a borderless one takes focus and `focusable: false` refuses it.
* Capabilities: position, always-on-top, focusable, opacity, icon (the app's Dock icon, one per app), keep-awake (an IOPM display-sleep assertion), attention (Dock bounce until active), exclusive fullscreen, and the frame clock (a `CADisplayLink` on the view posting `WindowFrameDue`, macOS 14 and later). Hit tests wait for HumanInput.
* Aspect: an exact ratio is AppKit's own; a range is kept by correcting the size after each resize.
* Lending: the same three surfaces as the canvas (Metal layer, SDL window, GL context), from the shared `LendsAppkitSurfaces` trait.

## Human input

With Surface's HumanInput installed, the provider registers the `appkit` input engine and the `gamecontroller` pad source (Surface's default `pads.mac`). HumanInput starts the engine when the AppKit session connects, and the pad source on its first poll, with or without a window.

```php
use Surface\Contracts\HumanInput\GamepadButton;
use Surface\Contracts\HumanInput\Key;
use Surface\HumanInput\MagicAliases\HumanInput;

$keys = HumanInput::keyboard();
if ($keys->isPressed(Key::SPACE)) { $this->jump(); }
$this->name .= $keys->text();

foreach (HumanInput::gameControllers() as $pad) {        // 'gc-<hash>': a DualSense reads as "DualSense Wireless Controller"
    $this->x += 2 * $pad->leftStick()['x'];
    if ($pad->isPressed(GamepadButton::NORTH)) { $this->jump(); }
}
```

* **Keys** are macOS key codes: positions, not letters, named as SDL names them on macOS (Surface's `MacKeycodes`).
  * Text is what a key down types. Nothing is typed while Command or Control is held, and control and function-key characters are dropped.
  * A repeat types again and leaves the key down.
  * A key let go while Command is held still reads released: the engine reads events before AppKit sends them.
  * Caps Lock is one press per toggle.
* **The pointer** reads in the window of this package's under it, toolkit or staged, key or not, in points from the content's top left. Between events the engine follows the system pointer. Over no window of ours, `window()` is null and the last position stays. Buttons count when clicked in one of our windows, or let go over one.
* **The wheel** reads as SDL reads it on macOS: mouse ticks are whole steps, trackpads give tenths, and a natural scroll is turned back, so `dy > 0` is always rolled away from the user.
* **Switching away** (Cmd-Tab, a click on another app) releases every key and button.
* **Releases AppKit keeps to itself** still read: a button or slider being clicked, or a context menu open, dequeues its own events, so each poll releases what the system no longer holds (`NSEvent::pressedMouseButtons()`, `CGEventSourceKeyState`). A key let go over another of the app's windows (an About or save panel) reads released too.
* **Pads** are `gc-<hash>` devices for every toolkit's windows on the Mac, and for scripts with none.
  * GameController gives input to the active app only. The source asks for background events once a pad connects; `app('human-input')->pads()->backgroundMonitoring()` says whether macOS granted them.
  * macOS 15.4.x refuses them. In a sketch with a window, pads then follow focus as keys do: switching away releases them.
  * A script with no window on 15.4.x can never be the active app, so its pad reads throw, naming `'sdl3'`. Set `'pads' => ['mac' => 'sdl3']` in the app's `config/human-input.php` (with jovian/venusian-sdl3) to read pads there.
  * `setPlayerIndex(0..3)` lights a DualSense's player LEDs; `hardwareId()` is null.
* **While the engine runs,** each of our windows takes moved events (`setAcceptsMouseMovedEvents`), and an observer listens for the app resigning active. Both are undone at disconnect.

## Edit menu

A profile's edit roles become AppKit's own Edit items: `undo:`, `redo:`, `cut:`, `copy:`, `paste:` and `selectAll:` with no target, on Cmd-Z, Shift-Cmd-Z, Cmd-X, Cmd-C, Cmd-V and Cmd-A. AppKit sends them to the focused text field, text view or table, and greys them out when nothing focused takes them; a custom item in the same folder stays enabled. macOS routes Cmd-C and the rest to a text field only through the menu bar, so a window whose profile has no Edit folder gets none of them.

## Links

`link(name, text, url)` is a selectable label whose attributed text carries `NSLinkAttributeName` (an NSURL, or the URL string when NSURL cannot parse it) and the font: AppKit draws it as a link and opens it through NSWorkspace when it is clicked, in the browser for http and https, and nothing posts. A disabled link is a disabled control.

## Text areas

`textArea(name, text)` is an NSTextView in its NSScrollView: read-only and disabled both leave it selectable but not editable, it wraps or scrolls sideways, takes the monospaced system font when a `FontSpec` asks for monospace, paints line backgrounds as NSTextBlock paragraph styles (TextKit 1) and range colours as text attributes, selects whole lines, and reads and sets its scroll position as content fractions. A watched area posts `view.scrolled.<window>.<path>` (`ViewScrolled`, latest only) for scrolls the user makes; scrolls its own calls cause post nothing.

## Right-click mail

A right click in a window posts mail naming what was clicked: `view.right-clicked.<window>.<path>` for the innermost primitive under the pointer (`x`, `y` in that primitive, from its top-left), or `window.right-clicked.<window>` on bare content. A right click is the right button (a two-finger or Magic Mouse right click is one) or Ctrl-click. A disabled primitive posts nothing; native context menus still open.

```php
foreach ($mail as $m) {
    if ($m instanceof \Surface\Contracts\Windows\Mail\View\RightClicked) {
        $this->menuFor($m->path, $m->x, $m->y);
    }
}
```

