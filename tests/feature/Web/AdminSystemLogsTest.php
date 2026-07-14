<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\Web\WebAuthFixture;

/**
 * `Admin\SystemLogs` — list/preview/download/delete over `writable/logs/`,
 * gated by `authFilter` + `adminFilter`. Security-critical focus: every
 * filename entry point (`preview`/`download`/`delete`) must reject anything
 * outside the real, current set of `.log` files in that directory —
 * `../` escapes, absolute paths, and non-allowlisted names all 404 and
 * touch nothing.
 *
 * @internal
 */
final class AdminSystemLogsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use WebAuthFixture;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;

    private const FIXTURE_A = 'admin-system-logs-test-a.log';
    private const FIXTURE_B = 'admin-system-logs-test-b.log';

    protected function tearDown(): void
    {
        parent::tearDown();
        session()->destroy();
        @unlink(WRITEPATH . 'logs/' . self::FIXTURE_A);
        @unlink(WRITEPATH . 'logs/' . self::FIXTURE_B);
    }

    private function seedFixtureLog(string $name = self::FIXTURE_A, string $content = "line one\nline two\n"): void
    {
        file_put_contents(WRITEPATH . 'logs/' . $name, $content);
    }

    public function testGuestIsRedirectedToLoginOnEveryLogsRoute(): void
    {
        $this->withSession(['basicAuthAuthenticated' => 1]);

        $this->get('/admin/system/logs')->assertRedirectTo('/login');
        $this->get('/admin/system/logs/preview?file=x.log')->assertRedirectTo('/login');
        $this->get('/admin/system/logs/download?file=x.log')->assertRedirectTo('/login');
        $this->post('/admin/system/logs/delete', $this->withCsrf(['file' => 'x.log']))->assertRedirectTo('/login');
        $this->post('/admin/system/logs/delete-all', $this->withCsrf([]))->assertRedirectTo('/login');
    }

    public function testNonAdminClientIsRejectedByAdminGateOnEveryLogsRoute(): void
    {
        $client = $this->seedWebUser(['email' => 'gate-logs-client@example.com', 'zone' => 'client']);
        $this->loginAs($client);

        $this->withSession()->get('/admin/system/logs')->assertRedirectTo('/dashboard');
        $this->withSession()->get('/admin/system/logs/preview?file=x.log')->assertRedirectTo('/dashboard');
        $this->withSession()->get('/admin/system/logs/download?file=x.log')->assertRedirectTo('/dashboard');
        $this->withSession()->post('/admin/system/logs/delete', $this->withCsrf(['file' => 'x.log']))->assertRedirectTo('/dashboard');
        $this->withSession()->post('/admin/system/logs/delete-all', $this->withCsrf([]))->assertRedirectTo('/dashboard');
    }

    public function testAdminCanPreviewDownloadAndDeleteAnAllowlistedFile(): void
    {
        $admin = $this->seedWebUser(['email' => 'admin-logs@example.com', 'zone' => 'global']);
        $this->seedFixtureLog();
        $this->loginAs($admin);

        $preview = $this->withSession()->get('/admin/system/logs/preview?file=' . self::FIXTURE_A);
        $preview->assertStatus(200);
        $json = json_decode($preview->getJSON(), true);
        $this->assertSame('success', $json['status']);
        $this->assertSame(self::FIXTURE_A, $json['name']);
        $this->assertStringContainsString('line one', $json['content']);
        $this->assertStringContainsString('line two', $json['content']);

        // DownloadResponse builds its Content-Disposition/Content-Length
        // headers inside send(), which the Feature test harness doesn't
        // invoke — so here we can only assert the allowlist accepted the
        // file (200, not 404). The 404 path (rejected filenames) is the
        // security-critical half and is covered by the traversal tests below.
        $download = $this->withSession()->get('/admin/system/logs/download?file=' . self::FIXTURE_A);
        $download->assertStatus(200);

        $delete = $this->withSession()->post('/admin/system/logs/delete', $this->withCsrf(['file' => self::FIXTURE_A]));
        $delete->assertStatus(200);
        $this->assertSame('success', json_decode($delete->getJSON(), true)['status']);
        $this->assertFileDoesNotExist(WRITEPATH . 'logs/' . self::FIXTURE_A);
    }

    public function testDeleteAllRemovesOnlyRealLogFiles(): void
    {
        $admin = $this->seedWebUser(['email' => 'admin-logs-all@example.com', 'zone' => 'global']);
        $this->seedFixtureLog(self::FIXTURE_A);
        $this->seedFixtureLog(self::FIXTURE_B);
        $this->loginAs($admin);

        $result = $this->withSession()->post('/admin/system/logs/delete-all', $this->withCsrf([]));

        $result->assertStatus(200);
        $json = json_decode($result->getJSON(), true);
        $this->assertGreaterThanOrEqual(2, $json['deleted']);
        $this->assertFileDoesNotExist(WRITEPATH . 'logs/' . self::FIXTURE_A);
        $this->assertFileDoesNotExist(WRITEPATH . 'logs/' . self::FIXTURE_B);
    }

    /**
     * @dataProvider traversalFilenameProvider
     */
    public function testPreviewRejectsPathTraversalAndUnallowlistedNames(string $malicious): void
    {
        $admin = $this->seedWebUser(['email' => 'admin-logs-trav1@example.com', 'zone' => 'global']);
        $this->loginAs($admin);

        $result = $this->withSession()->get('/admin/system/logs/preview?file=' . rawurlencode($malicious));

        $result->assertStatus(404);
    }

    /**
     * @dataProvider traversalFilenameProvider
     */
    public function testDownloadRejectsPathTraversalAndUnallowlistedNames(string $malicious): void
    {
        $admin = $this->seedWebUser(['email' => 'admin-logs-trav2@example.com', 'zone' => 'global']);
        $this->loginAs($admin);

        $result = $this->withSession()->get('/admin/system/logs/download?file=' . rawurlencode($malicious));

        $result->assertStatus(404);
    }

    /**
     * @dataProvider traversalFilenameProvider
     */
    public function testDeleteRejectsPathTraversalAndUnallowlistedNamesAndDeletesNothing(string $malicious): void
    {
        $admin = $this->seedWebUser(['email' => 'admin-logs-trav3@example.com', 'zone' => 'global']);
        $this->loginAs($admin);

        $result = $this->withSession()->post('/admin/system/logs/delete', $this->withCsrf(['file' => $malicious]));

        $result->assertStatus(404);
        // The traversal target used in this fixture set (.env) must survive untouched.
        $this->assertFileExists(ROOTPATH . '.env');
    }

    /**
     * @return iterable<string, list<string>>
     */
    public static function traversalFilenameProvider(): iterable
    {
        yield 'relative traversal to .env' => ['../../.env'];
        yield 'absolute path to .env' => [ROOTPATH . '.env'];
        yield 'non-log file in app tree' => ['../../app/Config/App.php'];
        yield 'wrong extension in logs dir' => ['index.html'];
        yield 'nonexistent log name' => ['does-not-exist.log'];
    }
}
