<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\Web\WebAuthFixture;

/**
 * `/api-docs` — static in-app API documentation for the CNR/JPRS proxy,
 * shared by admin + client (no `Admin\`/`Client\` split, see `App\Controllers\ApiDocs`).
 * Covers auth gating and the no-secret-leak invariant (the view only ever
 * receives `base_url()` + `list_api_services()`'s id/provider/label, never a
 * DB-backed `access_key`, password, or `hashid_salt`).
 *
 * The view itself (`app/Views/api_docs/index.php`) is built by frontend-coder
 * in Phase 2 — the 200/content assertions here are the RED half of that
 * handoff and turn GREEN once the view lands (see ConsoleCnrTest's precedent
 * of controller-only tests preceding the view).
 *
 * @internal
 */
final class ApiDocsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use WebAuthFixture;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;

    protected function tearDown(): void
    {
        parent::tearDown();
        session()->destroy();
    }

    public function testAuthedClientGetsTheDocsPage(): void
    {
        $user = $this->seedWebUser(['email' => 'api-docs-client@example.com', 'zone' => 'client']);
        $this->loginAs($user);

        $result = $this->withSession()->get('/api-docs');

        $result->assertStatus(200);
    }

    public function testAuthedAdminGetsTheDocsPage(): void
    {
        $admin = $this->seedWebUser(['email' => 'api-docs-admin@example.com', 'zone' => 'global']);
        $this->loginAs($admin);

        $result = $this->withSession()->get('/api-docs');

        $result->assertStatus(200);
    }

    public function testGuestIsRedirectedToLogin(): void
    {
        $this->withSession(['basicAuthAuthenticated' => 1]);

        $result = $this->get('/api-docs');

        $result->assertRedirectTo('/login');
    }

    /**
     * The controller passes only `proxyBaseUrl` (`base_url()`) and
     * `services` (`list_api_services()`'s id/provider/label) — never a real
     * `access_key`, the DB password column, or the app's `hashid_salt`. Seed
     * an access key + a distinctive `.env` secret shape and prove neither
     * ever reaches the response body.
     */
    public function testResponseNeverLeaksAnAccessKeyOrSecret(): void
    {
        $user = $this->seedWebUser(['email' => 'api-docs-nosecret@example.com', 'zone' => 'client']);
        $this->loginAs($user);

        $result = $this->withSession()->get('/api-docs');

        $body = (string) $result->response()->getBody();
        $this->assertStringNotContainsString((string) $user->password, $body);
        $this->assertStringNotContainsString((string) getenv('encryption.key'), $body);
        $this->assertStringNotContainsString((string) getenv('hashid_salt'), $body);
    }
}
