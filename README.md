# jovian/venusian-appkit

AppKit toolkit driver for the Venusian Surface bridge.

## Canvas

`AppkitCanvas` shows a `TKCanvas`'s framebuffer as the contents of a layer-backed view: the RGBA8 bytes become a `CGImage` (sRGB, the fourth byte skipped, so opaque), stretched over the view's bounds.

An ext-fb framebuffer is piped. `present()` hands the framebuffer's memory to `CFData::create` by address, and Core Graphics copies what it needs. No pixel byte passes through PHP. A framebuffer held in PHP (the native driver) takes the same path from a string.

A GPU engine borrows the view's layer instead. With ext-metal loaded, `surfaces()` is `[METAL_LAYER]`: the canvas lends a `CAMetalLayer` (ext-metal) as the view's layer to the `metal` engine (jovian/venusian-metal), whose frames reach the window with no pixel through PHP. `reclaim()` restores a backing layer with gravity and clipping, and the canvas shows its own framebuffer again.

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
