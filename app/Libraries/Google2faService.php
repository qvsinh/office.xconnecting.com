<?php

namespace App\Libraries;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use PragmaRX\Google2FA\Google2FA;

/**
 * Thin wrapper over `pragmarx/google2fa` (TOTP secret generation + code
 * verification) and `bacon/bacon-qr-code` (QR rendering) — the rest of the
 * app depends on this contract, never the SDK types directly. Used by
 * `Account` (enrollment) and `Auth::verify2fa()` (login second factor).
 */
class Google2faService
{
    // Label shown in the authenticator app, next to the 6-digit code.
    //
    // A constant on purpose: wiring it to site_name() would give this crypto
    // helper a DB dependency. It said 'CI4 Starter' until now, which is what
    // everyone enrolling 2FA actually saw on their phone — the `.env` key that
    // looked like it controlled this (`security.2fa.name`) was never read by
    // anything and has been removed.
    //
    // Changing it is safe: the issuer is only baked into the otpauth:// URI at
    // enrolment, so existing secrets keep verifying and already-enrolled phones
    // keep the label they were given.
    private const ISSUER = 'Office XCN';

    private Google2FA $engine;

    public function __construct(?Google2FA $engine = null)
    {
        $this->engine = $engine ?? new Google2FA();
    }

    /**
     * A fresh base32 TOTP secret for enrollment. Never persisted by this
     * class — the caller decides where it lives until confirmed.
     */
    public function generateSecret(): string
    {
        return $this->engine->generateSecretKey();
    }

    /**
     * Verifies a 6-digit code against a secret, allowing the library's
     * default clock-drift window either side of the current time slot.
     */
    public function verify(string $secret, string $code): bool
    {
        return (bool) $this->engine->verifyKey($secret, $code);
    }

    /**
     * The `otpauth://totp/...` URI an authenticator app scans/imports.
     */
    public function otpauthUrl(string $accountLabel, string $secret): string
    {
        return $this->engine->getQRCodeUrl(self::ISSUER, $accountLabel, $secret);
    }

    /**
     * Renders an otpauth:// URI as an inline SVG data URI — no filesystem
     * writes, no external image service, so the secret only ever transits
     * this one HTTP response.
     */
    public function qrCodeDataUri(string $otpauthUrl): string
    {
        $renderer = new ImageRenderer(new RendererStyle(240), new SvgImageBackEnd());
        $svg      = (new Writer($renderer))->writeString($otpauthUrl);

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }
}
