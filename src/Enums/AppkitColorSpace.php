<?php

namespace Jovian\Toolkits\Appkit\Enums;

/**
 * A Core Graphics color space name. The value is the kCGColorSpace* string
 * CGColorSpace::createWithName() accepts.
 */
enum AppkitColorSpace: string
{
    /** Standard sRGB. */
    case SRGB = 'kCGColorSpaceSRGB';

    /** Linear sRGB. */
    case LINEAR_SRGB = 'kCGColorSpaceLinearSRGB';

    /** Extended sRGB. */
    case EXTENDED_SRGB = 'kCGColorSpaceExtendedSRGB';

    /** Extended linear sRGB. */
    case EXTENDED_LINEAR_SRGB = 'kCGColorSpaceExtendedLinearSRGB';

    /** Display P3. */
    case DISPLAY_P3 = 'kCGColorSpaceDisplayP3';

    /** Extended Display P3. */
    case EXTENDED_DISPLAY_P3 = 'kCGColorSpaceExtendedDisplayP3';

    /** Adobe RGB 1998. */
    case ADOBE_RGB_1998 = 'kCGColorSpaceAdobeRGB1998';

    /** Generic linear RGB. */
    case GENERIC_RGB_LINEAR = 'kCGColorSpaceGenericRGBLinear';

    /** ITU-R BT.2020. */
    case ITUR_2020 = 'kCGColorSpaceITUR_2020';

    /** BT.2020 with sRGB gamma. */
    case ITUR_2020_SRGB_GAMMA = 'kCGColorSpaceITUR_2020_sRGBGamma';

    /** Display P3 with HLG. */
    case DISPLAY_P3_HLG = 'kCGColorSpaceDisplayP3_HLG';

    /** Display P3 with PQ. */
    case DISPLAY_P3_PQ_EOTF = 'kCGColorSpaceDisplayP3_PQ_EOTF';

    /** ITU-R BT.2100 HLG. */
    case ITUR_2100_HLG = 'kCGColorSpaceITUR_2100_HLG';

    /** ITU-R BT.2100 PQ. */
    case ITUR_2100_PQ = 'kCGColorSpaceITUR_2100_PQ';

    /** Generic gamma 2.2 gray. */
    case GENERIC_GRAY_GAMMA_2_2 = 'kCGColorSpaceGenericGrayGamma2_2';

    /** Extended grayscale. */
    case EXTENDED_GRAY = 'kCGColorSpaceExtendedGray';

    /** Linear grayscale. */
    case LINEAR_GRAY = 'kCGColorSpaceLinearGray';

    /** Extended linear grayscale. */
    case EXTENDED_LINEAR_GRAY = 'kCGColorSpaceExtendedLinearGray';

    /** Generic CMYK. */
    case GENERIC_CMYK = 'kCGColorSpaceGenericCMYK';

    /** CIE XYZ. */
    case GENERIC_XYZ = 'kCGColorSpaceGenericXYZ';

    /** CIE Lab. */
    case GENERIC_LAB = 'kCGColorSpaceGenericLab';

    /** ACEScg. */
    case ACESCG_LINEAR = 'kCGColorSpaceACESCGLinear';
}
