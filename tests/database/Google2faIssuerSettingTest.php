<?php

use App\Libraries\Google2faService;
use App\Models\SiteVariableModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * The authenticator label comes from the `security_2fa_name` setting.
 *
 * This is the database-backed half of `Google2faServiceTest`, which can only see
 * the fallback. It exists because the label is the one piece of 2FA a user reads,
 * and it was wrong — hardcoded to 'CI4 Starter' — while an env key that looked
 * like it controlled the label was never read at all.
 *
 * @internal
 */
final class Google2faIssuerSettingTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;

    private function issuerFrom(string $url): string
    {
        $this->assertSame(1, preg_match('/[?&]issuer=([^&]+)/', $url, $m), 'No issuer in ' . $url);

        return urldecode($m[1]);
    }

    private function url(): string
    {
        $service = new Google2faService();

        return $service->otpauthUrl('user@example.com', $service->generateSecret());
    }

    public function testTheSeededValueIsUsed(): void
    {
        // Seeded by 2026-08-05-010000_SeedMailAndTwoFactorVariables.
        $this->assertSame('Office.XCN', $this->issuerFrom($this->url()));
    }

    public function testTheAdminCanRenameIt(): void
    {
        model(SiteVariableModel::class)->setValue(Google2faService::ISSUER_SETTING, 'XCN Office HQ');

        $this->assertSame('XCN Office HQ', $this->issuerFrom($this->url()));
    }

    /**
     * A blank setting must not produce a nameless entry in the authenticator app —
     * the user would see a bare code with nothing saying what it unlocks.
     */
    public function testABlankSettingFallsBackToTheDefault(): void
    {
        model(SiteVariableModel::class)->setValue(Google2faService::ISSUER_SETTING, '   ');

        $this->assertSame(Google2faService::DEFAULT_ISSUER, $this->issuerFrom($this->url()));
    }

    /**
     * Renaming must not invalidate anyone's existing enrolment: the issuer is only
     * a label in the URI, never an input to the TOTP calculation.
     */
    public function testRenamingDoesNotInvalidateAnExistingSecret(): void
    {
        $service = new Google2faService();
        $secret  = $service->generateSecret();
        // The service exposes no "current code", so the engine produces one — the
        // same engine it verifies with.
        $code = (new PragmaRX\Google2FA\Google2FA())->getCurrentOtp($secret);

        model(SiteVariableModel::class)->setValue(Google2faService::ISSUER_SETTING, 'Something Else Entirely');

        $this->assertTrue((new Google2faService())->verify($secret, $code), 'The code must still verify after a rename.');
    }
}
