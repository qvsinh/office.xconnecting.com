<?php

use App\Libraries\Google2faService;
use CodeIgniter\Test\CIUnitTestCase;
use PragmaRX\Google2FA\Google2FA;

/**
 * `Google2faService` — the thin wrapper the rest of the app depends on
 * instead of the pragmarx/bacon SDKs directly. No DB/session involved.
 *
 * @internal
 */
final class Google2faServiceTest extends CIUnitTestCase
{
    public function testVerifyAcceptsTheCurrentCodeForAKnownSecret(): void
    {
        $engine  = new Google2FA();
        $secret  = $engine->generateSecretKey();
        $code    = $engine->getCurrentOtp($secret);
        $service = new Google2faService();

        $this->assertTrue($service->verify($secret, $code));
    }

    public function testVerifyRejectsAWrongCode(): void
    {
        $engine  = new Google2FA();
        $secret  = $engine->generateSecretKey();
        $service = new Google2faService();

        $this->assertFalse($service->verify($secret, '000000'));
    }

    public function testGenerateSecretReturnsANonEmptyBase32String(): void
    {
        $secret = (new Google2faService())->generateSecret();

        $this->assertNotSame('', $secret);
        $this->assertMatchesRegularExpression('/^[A-Z2-7]+$/', $secret);
    }

    public function testOtpauthUrlContainsTheIssuerAndSecret(): void
    {
        $service = new Google2faService();
        $secret  = $service->generateSecret();

        $url = $service->otpauthUrl('user@example.com', $secret);

        $this->assertStringStartsWith('otpauth://totp/', $url);
        $this->assertStringContainsString($secret, $url);

        // The issuer appears twice — as the label prefix and as the `issuer`
        // parameter — and authenticator apps show a duplicated entry if the two
        // disagree. Asserted against each other rather than against a literal, so
        // renaming the issuer does not need this test edited.
        $this->assertSame(
            1,
            preg_match('#^otpauth://totp/([^:]+):[^?]+\?.*\bissuer=([^&]+)#', $url, $parts),
            'Unexpected otpauth URL shape: ' . $url,
        );
        $this->assertSame($parts[1], $parts[2], 'The label prefix and the issuer parameter must match.');

        // It must not still be the starter's placeholder — that is what every
        // enrolling user saw on their phone until it was renamed.
        $this->assertNotSame('CI4%20Starter', $parts[2], 'The authenticator issuer is still the starter default.');
        $this->assertSame('Office XCN', urldecode($parts[2]));
    }

    public function testQrCodeDataUriReturnsANonEmptySvgDataUri(): void
    {
        $service = new Google2faService();
        $otpauth = $service->otpauthUrl('user@example.com', $service->generateSecret());

        $dataUri = $service->qrCodeDataUri($otpauth);

        $this->assertStringStartsWith('data:image/svg+xml;base64,', $dataUri);
        $decoded = base64_decode(substr($dataUri, strlen('data:image/svg+xml;base64,')), true);
        $this->assertIsString($decoded);
        $this->assertStringContainsString('<svg', $decoded);
    }
}
