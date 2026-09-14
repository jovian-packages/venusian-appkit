<?php

namespace Jovian\Venusian\AppKit\Enums;

/** AppKit/NSOpenGL.h pixel-format attributes have no NS_ENUM; cited by header line as the typed proof does. */
enum NSOpenGLPixelFormatAttribute: int
{
    case DOUBLE_BUFFER = 5;   // NSOpenGL.h:62
    case COLOR_SIZE = 8;      // NSOpenGL.h:64
    case OPENGL_PROFILE = 99; // NSOpenGL.h:86
}
