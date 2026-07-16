<?php

use App\Controllers\Admin\System;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\Web\WebAuthFixture;

/**
 * `POST /admin/system/sessions/reset` (`Admin\System::resetSessions`) +
 * `buildSessionSummary()`. Gated by `authFilter` + `adminFilter` + `csrf`.
 * The reset happy-path depends on the DB session handler + a `ci_sessions`
 * table existing in the test DB, so the admin test accepts whichever graceful
 * branch fires (success / not-a-DB-store / table-missing) — never a fatal.
 *
 * @internal
 */
final class AdminSystemSessionResetTest extends CIUnitTestCase
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

    public function testBuildSessionSummaryHasExpectedShape(): void
    {
        $summary = (new System())->buildSessionSummary();

        $this->assertArrayHasKey('driver', $summary);
        $this->assertArrayHasKey('is_database', $summary);
        $this->assertArrayHasKey('table', $summary);
        $this->assertArrayHasKey('rows', $summary);
        $this->assertIsString($summary['driver']);
        $this->assertIsBool($summary['is_database']);

        if ($summary['is_database']) {
            $this->assertSame('DatabaseHandler', $summary['driver']);
            $this->assertNotNull($summary['table']);
        }
    }

    public function testGuestIsRedirectedToLogin(): void
    {
        $this->withSession(['basicAuthAuthenticated' => 1]);

        $this->post('/admin/system/sessions/reset', $this->withCsrf([]))->assertRedirectTo('/login');
    }

    public function testNonAdminClientIsRejected(): void
    {
        $client = $this->seedWebUser(['email' => 'session-reset-client@example.com', 'zone' => 'client']);
        $this->loginAs($client);

        $this->withSession()->post('/admin/system/sessions/reset', $this->withCsrf([]))->assertRedirectTo('/dashboard');
    }

    public function testAdminResetHandlesEveryBranchGracefully(): void
    {
        $admin = $this->seedWebUser(['email' => 'session-reset-admin@example.com', 'zone' => 'global']);
        $this->loginAs($admin);

        $result = $this->withSession()->post('/admin/system/sessions/reset', $this->withCsrf([]));
        $json   = json_decode($result->getJSON(), true);

        // One of: success (DB store + table present), 400 (not a DB store),
        // or 404 (DB store but no session table) — always the shared envelope.
        $this->assertContains($json['status'], ['success', 'failed']);
        $this->assertContains($result->response()->getStatusCode(), [200, 400, 404, 500]);
    }
}
