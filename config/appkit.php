<?php

use Jovian\Toolkits\Appkit\Enums\AppkitColorSpace;

return [

    'backend' => [

        /*
        |--------------------------------------------------------------------------
        | Stage
        |--------------------------------------------------------------------------
        |
        | Color space: the color space a canvas's frames are in. The window server
        | matches it to the display while it composites. A kCGColorSpace* name
        | ("kCGColorSpaceSRGB", "kCGColorSpaceDisplayP3", ...), or "window": the
        | window's own space, so nothing is matched and a frame's values reach
        | the display as they are.
        |
        */

        'stage' => [
            'color_space' => AppkitColorSpace::SRGB
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Video
    |--------------------------------------------------------------------------
    |
    | Driver: what plays a video view. "webview" shows the media in a
    | WKWebView. "avkit" plays it with an AVPlayer in an AVPlayerView,
    | through jovian/venusian-avkit. Another package or the app adds a
    | driver with AppkitAdapterDriver::video()->extend().
    |
    */

    'video' => [
        'driver' => 'webview',
    ],

];
