<?php

/*
| Prefer the package's own vendor when installed. Without it (no path repos
| here) run via surface's Pest binary, `../../venusian/surface/vendor/bin/pest`,
| and autoload this package, the typed binding and Surface from the sibling
| checkouts.
*/

$vendor = dirname(__DIR__).'/vendor/autoload.php';

if (is_file($vendor)) {
    require $vendor;
} else {
    /*
    | Surface's vendor carries Voyager\ (and Pest). The prefix loaders below
    | cover this package, the typed binding and Surface itself.
    */
    $surface_vendor = dirname(__DIR__, 3).'/venusian/surface/vendor/autoload.php';

    if (is_file($surface_vendor)) {
        require_once $surface_vendor;
    }

    $prefixes = [
        'Jovian\\Venusian\\AppKit\\' => dirname(__DIR__).'/src/',
        // DTO and enum classes are plain PHP; only method bodies touch ext-appkit.
        'Jovian\\Bindings\\AppKit\\' => dirname(__DIR__, 2).'/appkit/src/',
        'Surface\\' => dirname(__DIR__, 3).'/venusian/surface/src/Surface/',
    ];

    spl_autoload_register(function (string $class) use ($prefixes): void {
        foreach ($prefixes as $prefix => $dir) {
            if (! str_starts_with($class, $prefix)) {
                continue;
            }

            $file = $dir.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';

            if (is_file($file)) {
                require $file;
            }

            return;
        }
    });
}

spl_autoload_register(function (string $class): void {
    $prefix = 'Venusian\\AppKit\\Tests\\Support\\';

    if (! str_starts_with($class, $prefix) || class_exists($class, false)) {
        return;
    }

    $file = __DIR__.'/Support/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';

    if (is_file($file)) {
        require $file;
    }
});
