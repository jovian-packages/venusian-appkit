<?php

namespace Jovian\Toolkits\Appkit\Providers;

use Jovian\Toolkits\Appkit\AppkitAdapterDriver;
use Surface\Toolkits\ToolkitAdapterServiceProvider;

class VenusianAppkitServiceProvider extends ToolkitAdapterServiceProvider
{
    protected string $toolkit = 'appkit';

    protected string $driver = AppkitAdapterDriver::class;

    protected string $config = __DIR__.'/../../config/appkit.php';
}
