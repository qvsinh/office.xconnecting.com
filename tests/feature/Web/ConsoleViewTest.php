<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\Api\ApiKeyFixture;
use Tests\Support\Web\WebAuthFixture;

/**
 * View-rendering coverage for the CNR/JPRS developer consoles
 * (`console/_console.php`, entry views under `{admin,client}/api_console/*`).
 * Controller/model logic (run() happy-path, IDOR, disabled-key, cross-
 * provider, domain restriction, History, CSRF, admin-any-key) is already
 * covered by ConsoleCnrTest/ConsoleJprsTest — this suite closes the
 * remaining gap: does each console page actually render, does the embedded
 * key list scope correctly per zone, and — the #1 concern — does the raw
 * `access_key` secret ever reach the page.
 *
 * @internal
 */
final class ConsoleViewTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use WebAuthFixture;
    use ApiKeyFixture;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;

    private const CNR_SERVICE_ID  = 800;
    private const JPRS_SERVICE_ID = 801;

    protected function tearDown(): void
    {
        parent::tearDown();
        session()->destroy();
    }

    public static function consoleUrlProvider(): iterable
    {
        yield 'client cnr'  => ['client', '/console/cnr'];
        yield 'client jprs' => ['client', '/console/jprs'];
        yield 'admin cnr'   => ['admin', '/admin/console/cnr'];
        yield 'admin jprs'  => ['admin', '/admin/console/jprs'];
    }

    /**
     * @dataProvider consoleUrlProvider
     */
    public function testConsolePageRendersTheKeySelectorCommandInputParamsEditorAndRunControl(string $zone, string $url): void
    {
        $viewer = $zone === 'admin'
            ? $this->seedWebUser(['email' => 'console-view-admin-' . md5($url) . '@example.com', 'zone' => 'global'])
            : $this->seedWebUser(['email' => 'console-view-client-' . md5($url) . '@example.com', 'zone' => 'client']);
        $this->loginAs($viewer);

        $result = $this->withSession()->get($url);

        $result->assertStatus(200);
        $body = (string) $result->response()->getBody();
        $this->assertStringContainsString('id="key-select"', $body);
        $this->assertStringContainsString('id="command"', $body);
        $this->assertStringContainsString('+ Add parameter', $body);
        $this->assertStringContainsString('x-data="apiConsole(', $body);
        $this->assertStringContainsString('@click="run()"', $body);
    }

    public function testAdminConsolesIncludeOwnerInfoInTheKeyListButClientConsolesDoNot(): void
    {
        $owner = $this->seedWebUser(['email' => 'console-view-owner@example.com', 'zone' => 'client', 'name' => 'Console Owner Jones']);
        $this->seedApiKey($owner->id, 0, self::CNR_SERVICE_ID);
        // The key list is JSON-encoded then attribute-escaped as a whole
        // (`esc(json_encode($keysForJs), 'attr')`), so the expected fragment
        // must go through the same escaper, not a literal string match.

        $admin = $this->seedWebUser(['email' => 'console-view-admin2@example.com', 'zone' => 'global']);
        $this->loginAs($admin);
        $adminResult = $this->withSession()->get('/admin/console/cnr');
        $adminResult->assertStatus(200);
        $this->assertStringContainsString(esc('Console Owner Jones', 'attr'), (string) $adminResult->response()->getBody());

        session()->destroy();
        $this->loginAs($owner);
        $clientResult = $this->withSession()->get('/console/cnr');
        $clientResult->assertStatus(200);
        // The client console's own key list is not joined against `users` —
        // the owner's own name never appears in that payload.
        $this->assertStringNotContainsString(esc('Console Owner Jones', 'attr'), (string) $clientResult->response()->getBody());
    }

    public function testAccessKeySecretNeverAppearsOnAnyConsolePage(): void
    {
        $user = $this->seedWebUser(['email' => 'console-view-secret@example.com', 'zone' => 'client']);
        $key  = $this->seedApiKey($user->id, 0, self::CNR_SERVICE_ID);

        $this->loginAs($user);
        $clientResult = $this->withSession()->get('/console/cnr');
        $clientResult->assertStatus(200);
        $this->assertStringNotContainsString($key['token'], (string) $clientResult->response()->getBody());

        session()->destroy();
        $admin = $this->seedWebUser(['email' => 'console-view-secret-admin@example.com', 'zone' => 'global']);
        $this->loginAs($admin);
        $adminResult = $this->withSession()->get('/admin/console/cnr');
        $adminResult->assertStatus(200);
        $this->assertStringNotContainsString($key['token'], (string) $adminResult->response()->getBody());
    }

    public function testClientConsoleKeyListIsScopedToTheSessionUsersOwnKeyAdminSeesBoth(): void
    {
        $userA = $this->seedWebUser(['email' => 'console-view-a@example.com', 'zone' => 'client']);
        $userB = $this->seedWebUser(['email' => 'console-view-b@example.com', 'zone' => 'client']);
        $keyA  = $this->seedApiKey($userA->id, 0, self::CNR_SERVICE_ID);
        $keyB  = $this->seedApiKey($userB->id, 0, self::CNR_SERVICE_ID);

        $this->loginAs($userA);
        $result = $this->withSession()->get('/console/cnr');
        $result->assertStatus(200);
        $body = (string) $result->response()->getBody();
        $this->assertStringContainsString($this->keyIdJsonFragment($keyA['id']), $body);
        $this->assertStringNotContainsString($this->keyIdJsonFragment($keyB['id']), $body);

        session()->destroy();
        $admin = $this->seedWebUser(['email' => 'console-view-admin3@example.com', 'zone' => 'global']);
        $this->loginAs($admin);
        $adminResult = $this->withSession()->get('/admin/console/cnr');
        $adminResult->assertStatus(200);
        $adminBody = (string) $adminResult->response()->getBody();
        $this->assertStringContainsString($this->keyIdJsonFragment($keyA['id']), $adminBody);
        $this->assertStringContainsString($this->keyIdJsonFragment($keyB['id']), $adminBody);
    }

    public function testJprsConsoleKeyListIsScopedToTheSessionUsersOwnKey(): void
    {
        $userA = $this->seedWebUser(['email' => 'console-view-jprs-a@example.com', 'zone' => 'client']);
        $userB = $this->seedWebUser(['email' => 'console-view-jprs-b@example.com', 'zone' => 'client']);
        $keyA  = $this->seedApiKey($userA->id, 0, self::JPRS_SERVICE_ID);
        $keyB  = $this->seedApiKey($userB->id, 0, self::JPRS_SERVICE_ID);

        $this->loginAs($userA);
        $result = $this->withSession()->get('/console/jprs');
        $result->assertStatus(200);
        $body = (string) $result->response()->getBody();
        $this->assertStringContainsString($this->keyIdJsonFragment($keyA['id']), $body);
        $this->assertStringNotContainsString($this->keyIdJsonFragment($keyB['id']), $body);
    }

    public function testResponseViewerBindsViaXTextAndNeverXHtml(): void
    {
        $user = $this->seedWebUser(['email' => 'console-view-xtext@example.com', 'zone' => 'client']);
        $this->loginAs($user);

        $result = $this->withSession()->get('/console/cnr');
        $result->assertStatus(200);
        $body = (string) $result->response()->getBody();

        $this->assertStringContainsString('x-text="responseText || \'Run a command to see the response here.\'"', $body);
        $this->assertStringNotContainsString('x-html', $body);
    }

    public function testGuestIsRedirectedToLoginFromBothConsoles(): void
    {
        $this->withSession(['basicAuthAuthenticated' => 1]);
        $this->get('/console/cnr')->assertRedirectTo('/login');

        $this->withSession(['basicAuthAuthenticated' => 1]);
        $this->get('/admin/console/cnr')->assertRedirectTo('/login');
    }

    public function testClientSessionIsBlockedFromAdminConsoleByAdminGate(): void
    {
        $client = $this->seedWebUser(['email' => 'console-view-gate@example.com', 'zone' => 'client']);
        $this->loginAs($client);

        $result = $this->withSession()->get('/admin/console/cnr');

        $result->assertRedirectTo('/dashboard');
    }

    /**
     * The JSON key list is attribute-escaped as a whole
     * (`esc(json_encode($keysForJs), 'attr')`), so build the expected
     * fragment through the same escaper rather than guessing its per-char
     * encoding rules. `"id":<n>,"service_id":` uniquely identifies one key
     * row (id followed immediately by service_id) — it can't collide with
     * any other numeric value on the page.
     */
    private function keyIdJsonFragment(int $keyId): string
    {
        return esc('"id":' . $keyId . ',"service_id":', 'attr');
    }
}
