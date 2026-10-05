# jovian/venusian-appkit

AppKit toolkit driver for the Venusian Surface bridge.

## Canvas

`AppkitCanvas` shows a `TKCanvas`'s framebuffer as the contents of a layer-backed view: the RGBA8 bytes become a `CGImage` (sRGB, the fourth byte skipped, so opaque), stretched over the view's bounds.

An ext-fb framebuffer is piped. `present()` hands the framebuffer's memory to `CFData::create` by address, and Core Graphics copies what it needs. No pixel byte passes through PHP. A framebuffer held in PHP (the native driver) takes the same path from a string.
