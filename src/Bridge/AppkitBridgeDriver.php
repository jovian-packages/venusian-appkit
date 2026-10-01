<?php

namespace Jovian\Toolkits\Appkit\Bridge;

use Surface\Bridge\BridgedToolkitSession;
use Surface\Bridge\ToolkitBridgeDriver;
use Jovian\Toolkits\Appkit\Contracts\Bridge\AppkitBridgeDriver as BridgeContract;

class AppkitBridgeDriver extends ToolkitBridgeDriver implements BridgeContract
{

    public function connect(): BridgedToolkitSession
    {
        // TODO: Implement connect() method.
    }
}