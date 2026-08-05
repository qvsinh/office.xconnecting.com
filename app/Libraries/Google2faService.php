<?php

namespace App\Libraries;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use PragmaRX\Google2FA\Google2FA;
use Throwable;

/**
 * Thin wrapper over `pragmarx/google2fa` (TOTP secret generation + code
 * verification) and `bacon/bacon-qr-code` (QR rendering) — the rest of the
 * app depends on this contract, never the SDK types directly. Used by
 * `Account` (enrollment) and `Auth::verify2fa()` (login second factor).
 */
class Google2faService
{
    /**
     * Fallback label, used when the setting is missing or the database is
     * unreachable. Enrolment must not fail because a settings row is absent.
     */
    public const DEFAULT_ISSUER = 'Office.XCN';

    /**
     * Admin-editable setting holding the label.
     */
    public const ISSUER_SETTING = 'security_2fa_name';

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
        return $this->engine->getQRCodeUrl($this->issuer(), $accountLabel, $secret);
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

    /**
     * The label shown beside the 6-digit code, from `security_2fa_name`.
     *
     * This reintroduces a database read into what was deliberately a
     * dependency-free crypto helper. It is a considered trade: the office needs to
     * rename itself without a deploy, and the previous arrangement was worse —
     * an env key (`security.2fa.name`) that looked like it controlled this while
     * the real label was a hardcoded constant nobody noticed said "CI4 Starter".
     *
     * Any failure falls back to the constant rather than propagating: a missing
     * settings table must not stop somebody enrolling in 2FA.
     */
    private function issuer(): string
    {
        try {
            helper('setting');

            $label = trim((string) get_site_variable(self::ISSUER_SETTING, self::DEFAULT_ISSUER));

            return $label === '' ? self::DEFAULT_ISSUER : $label;
        } catch (Throwable $e) {
            log_message('warning', 'Falling back to the default 2FA issuer: {msg}', ['msg' => $e->getMessage()]);

            return self::DEFAULT_ISSUER;
        }
    }
}
