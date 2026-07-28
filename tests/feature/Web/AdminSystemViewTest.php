<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\Web\WebAuthFixture;

/**
 * View-rendering coverage for the System admin card (`admin/system/info`,
 * `admin/system/migrations`, `admin/system/logs`). Controller/model logic
 * (no-secret-leak on the data array, migration merge, path traversal) is
 * already covered by AdminSystemInfoTest (database), AdminSystemMigrationsTest
 * (unit), and AdminSystemLogsTest (feature) — this suite closes the
 * remaining gap: do the views actually render, is the rendered HTML also
 * free of secrets (belt-and-suspenders on top of the data-level test), and
 * — the #1 concern — is the log preview surface (untrusted file content)
 * bound via `x-text` only, never `x-html`.
 *
 * @internal
 */
final class AdminSystemViewTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use WebAuthFixture;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;

    private const XSS_SCRIPT = '<script>alert(1)</script>';
    private const FIXTURE_LOG = 'admin-system-view-test.log';

    protected function tearDown(): void
    {
        parent::tearDown();
        session()->destroy();
        @unlink(WRITEPATH . 'logs/' . self::FIXTURE_LOG);
    }

    public function testAdminCanRenderSystemInfoPage(): void
    {
        $admin = $this->seedWebUser(['email' => 'view-system-info@example.com', 'zone' => 'admin']);
        $this->loginAs($admin);

        $result = $this->withSession()->get('/admin/system');

        $result->assertStatus(200);
        $result->assertSee('System');
        $result->assertSee('Version &amp; environment');
        $result->assertSee('Record counts');
        $result->assertSee('Writable directories');
    }

    public function testSystemInfoRenderedHtmlContainsNoKnownSecretValue(): void
    {
        $admin = $this->seedWebUser(['email' => 'view-system-nosecret@example.com', 'zone' => 'admin']);
        $this->loginAs($admin);

        $result = $this->withSession()->get('/admin/system');
        $result->assertStatus(200);
        $body = (string) $result->response()->getBody();

        $secrets = [
            getenv('database.tests.password'),
            getenv('database.default.password'),
            getenv('hashid_salt'),
            getenv('encryption.key'),
            getenv('app.basic.auth.password'),
        ];

        foreach ($secrets as $secret) {
            if ($secret === false || $secret === '') {
                continue;
            }

            $this->assertStringNotContainsString((string) $secret, $body);
        }
    }

    public function testAdminCanRenderMigrationsPage(): void
    {
        $admin = $this->seedWebUser(['email' => 'view-system-migrations@example.com', 'zone' => 'admin']);
        $this->loginAs($admin);

        $result = $this->withSession()->get('/admin/system/migrations');

        $result->assertStatus(200);
        $result->assertSee('Database migrations');
        $result->assertSeeElement('table');
        $body = (string) $result->response()->getBody();
        $this->assertStringContainsString('name="keyword"', $body);
        // read-only: no run/rollback endpoint is ever referenced (the page
        // copy legitimately mentions "rollback" in prose pointing at the CLI)
        $this->assertStringNotContainsString('/migrate', $body);
    }

    public function testAdminCanRenderLogsPageWithAFixtureFileListed(): void
    {
        $admin = $this->seedWebUser(['email' => 'view-system-logs@example.com', 'zone' => 'admin']);
        file_put_contents(WRITEPATH . 'logs/' . self::FIXTURE_LOG, "line one\n");
        $this->loginAs($admin);

        $result = $this->withSession()->get('/admin/system/logs');

        $result->assertStatus(200);
        $result->assertSee('System logs');
        $result->assertSeeElement('table');
        $body = (string) $result->response()->getBody();
        $this->assertStringContainsString(self::FIXTURE_LOG, $body);
    }

    /**
     * The preview endpoint returns the raw log text as JSON data (no HTML
     * rendering happens server-side, so an XSS payload in the log file is
     * safe to return verbatim there); the safety boundary is the view,
     * which must bind that content via `x-text` and never `x-html`.
     */
    public function testLogPreviewReturnsRawContentAndViewBindsItViaXTextNeverXHtml(): void
    {
        $admin = $this->seedWebUser(['email' => 'view-system-logs-xss@example.com', 'zone' => 'admin']);
        file_put_contents(WRITEPATH . 'logs/' . self::FIXTURE_LOG, self::XSS_SCRIPT . "\n");
        $this->loginAs($admin);

        $preview = $this->withSession()->get('/admin/system/logs/preview?file=' . self::FIXTURE_LOG);
        $preview->assertStatus(200);
        $json = json_decode($preview->getJSON(), true);
        $this->assertSame('success', $json['status']);
        $this->assertStringContainsString(self::XSS_SCRIPT, $json['content']);

        $index = $this->withSession()->get('/admin/system/logs');
        $index->assertStatus(200);
        $body = (string) $index->response()->getBody();

        // Log content itself is never embedded in the list page (only
        // fetched on demand into previewContent) so the raw script string
        // cannot appear in the rendered list HTML.
        $this->assertStringNotContainsString(self::XSS_SCRIPT, $body);
        $this->assertStringContainsString('x-text="previewContent"', $body);
        // No actual x-html directive anywhere (the view has a descriptive
        // HTML comment mentioning the word, hence the narrower `x-html=` check)
        $this->assertStringNotContainsString('x-html=', $body);
    }

    public function testGuestIsRedirectedToLoginFromAllThreeSystemPages(): void
    {
        $this->withSession(['basicAuthAuthenticated' => 1]);
        $this->get('/admin/system')->assertRedirectTo('/login');

        $this->withSession(['basicAuthAuthenticated' => 1]);
        $this->get('/admin/system/migrations')->assertRedirectTo('/login');

        $this->withSession(['basicAuthAuthenticated' => 1]);
        $this->get('/admin/system/logs')->assertRedirectTo('/login');
    }

    public function testNonAdminClientIsBlockedFromAllThreeSystemPages(): void
    {
        $client = $this->seedWebUser(['email' => 'view-system-gate-client@example.com', 'zone' => 'user']);
        $this->loginAs($client);

        $this->withSession()->get('/admin/system')->assertRedirectTo('/dashboard');
        $this->withSession()->get('/admin/system/migrations')->assertRedirectTo('/dashboard');
        $this->withSession()->get('/admin/system/logs')->assertRedirectTo('/dashboard');
    }
}
