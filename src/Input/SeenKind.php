<?php

namespace Jovian\Toolkits\Appkit\Input;

/** The AppKit events the appkit input engine reads. */
enum SeenKind
{
    case Key;
    case Flags;
    case Button;
    case Motion;
    case Wheel;
}
