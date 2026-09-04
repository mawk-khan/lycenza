<?php

namespace App\Support\Auth\Mfa;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * Renders an enrollment QR code entirely server-side (bacon/bacon-qr-
 * code) -- no external QR-generation API call, matching Phase 0H.4D-P1
 * section 6. Never called with anything but the current, in-memory
 * otpauth:// URI for the User's own pending enrollment -- the caller
 * (MfaEnrollmentService) is responsible for never logging that URI.
 */
class MfaQrCodeGenerator
{
    public function svgFor(string $otpAuthUri): string
    {
        $renderer = new ImageRenderer(
            new RendererStyle(240),
            new SvgImageBackEnd,
        );

        return (new Writer($renderer))->writeString($otpAuthUri);
    }
}
