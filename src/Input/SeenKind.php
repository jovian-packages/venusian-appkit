<?php

namespace Jovian\Toolkits\Appkit\Input;

/** The AppKit events a canvas's input is made of. */
enum SeenKind
{
    /** keyDown: / keyUp: on the canvas as first responder, or a Command key-up AppKit keeps from it */
    case Key;
    /** flagsChanged: on the canvas as first responder */
    case Flags;
    /** insertText: from the canvas's interpretKeyEvents: */
    case Text;
    case Button;
    /** a move or a drag, with or without a button, onto, over or off the canvas */
    case Pointer;
    case Scroll;
    case Magnify;
    case Rotate;
    case Pressure;
}
