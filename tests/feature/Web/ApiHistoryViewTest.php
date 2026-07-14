<?php

use App\Models\ApiHistoryModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\Web\WebAuthFixture;

/**
 * View-rendering coverage for the API history viewer
 * (`client/api_history/index.php`, `admin/api_history/index.php`,
 * `api_history/_preview_modal.php`). Controller/model logic (IDOR, scoping,
 * soft-delete, admin user-filter) is already covered by
 * ApiHistoryModelTest, ClientApiHistoryTest, and AdminApiHistoryTest — this
 * suite closes the remaining gap: does each view actually render, does the
 * filter bar repopulate from the query string, and — the #1 concern — is
 * the preview surface (`params`/`response`/`domain`, which can contain
 * registrar-echoed markup) safe, given the modal is fed by a JSON `fetch()`
 * and binds everything via Alpine `x-text` (never `x-html`).
 *
 * @internal
 */
final class ApiHistoryViewTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use WebAuthFixture;

    private const XSS_SCRIPT = '<script>alert(1)</script>';
    private const XSS_BREAK  = '"><img src=x onerror=alert(1)>';

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;

    protected function tearDown(): void
    {
        parent::tearDown();
        session()->destroy();
    }

    public function testClientIndexRendersFilterBarAndTable(): void
    {
        $user = $this->seedWebUser(['email' => 'view-hist-client@example.com', 'zone' => 'client']);
        $this->seedRow(['user_id' => $user->id]);
        $this->loginAs($user);

        $result = $this->withSession()->get('/api-history');

        $result->assertStatus(200);
        $result->assertSee('API history');
        $result->assertSeeElement('table');
        $result->assertSeeElement('form');
        $body = (string) $result->response()->getBody();
        $this->assertStringContainsString('name="keyword"', $body);
        $this->assertStringContainsString('apiHistoryClient(', $body);
    }

    public function testAdminIndexRendersUserFilterAndUserColumn(): void
    {
        $admin = $this->seedWebUser(['email' => 'view-hist-admin@example.com', 'zone' => 'global']);
        $owner = $this->seedWebUser(['email' => 'view-hist-owner@example.com', 'zone' => 'client']);
        $this->seedRow(['user_id' => $owner->id]);
        $this->loginAs($admin);

        $result = $this->withSession()->get('/admin/api-history');

        $result->assertStatus(200);
        $result->assertSee('API history');
        $result->assertSee('User');
        $result->assertSeeElement('table');
        $body = (string) $result->response()->getBody();
        $this->assertStringContainsString('name="user"', $body); // the admin-only user filter
        $this->assertStringContainsString('apiHistoryAdmin(', $body);
    }

    /**
     * The modal is client-rendered from the preview JSON, so the safety
     * boundary is in the view, not the endpoint: prove the endpoint returns
     * the row as plain JSON data (no HTML rendering happens server-side),
     * and prove the rendered index view binds params/response/domain only
     * via `x-text`/`prettyPrint(...)` — never `x-html` anywhere in the page.
     */
    public function testPreviewReturnsRawDataAndViewBindsItViaXTextNeverXHtml(): void
    {
        $user  = $this->seedWebUser(['email' => 'view-hist-xss@example.com', 'zone' => 'client']);
        $rowId = $this->seedRow([
            'user_id'  => $user->id,
            'domain'   => self::XSS_BREAK,
            'params'   => '{"note":"' . self::XSS_SCRIPT . '"}',
            'response' => '{"note":"' . self::XSS_SCRIPT . '"}',
        ]);
        $this->loginAs($user);

        // The preview endpoint is a JSON API response (application/json),
        // not an HTML document — the browser never executes script from
        // it. Returning the stored value verbatim is correct (it's the
        // caller's own data); the escaping obligation lives in the view.
        $preview = $this->withSession()->get('/api-history/preview?id=' . $rowId);
        $preview->assertStatus(200);
        $previewJson = json_decode($preview->getJSON(), true);
        $this->assertSame('success', $previewJson['status']);
        $this->assertStringContainsString(self::XSS_SCRIPT, $previewJson['row']['params']);
        $this->assertStringContainsString(self::XSS_SCRIPT, $previewJson['row']['response']);

        $index = $this->withSession()->get('/api-history');
        $index->assertStatus(200);
        $body = (string) $index->response()->getBody();

        // Params/response never touch the list payload at all (excluded
        // from $itemsForJs) — they only ever reach the DOM through the
        // preview fetch + x-text binding, so the raw script string cannot
        // appear in the rendered list page.
        $this->assertStringNotContainsString(self::XSS_SCRIPT, $body);
        $this->assertStringContainsString('x-text="prettyPrint(previewRow?.params)"', $body);
        $this->assertStringContainsString('x-text="prettyPrint(previewRow?.response)"', $body);
        $this->assertStringNotContainsString('x-html', $body);
    }

    /**
     * `domain` DOES reach the rendered list page (embedded in the row list
     * fed to Alpine via `esc(json_encode($itemsForJs), 'attr')`), so prove
     * that path escapes correctly too: the raw markup must never appear
     * unescaped, and the HTML-entity-escaped form must be present instead.
     */
    public function testDomainWithHtmlBreakingCharsIsAttributeEscapedOnTheListPage(): void
    {
        $user = $this->seedWebUser(['email' => 'view-hist-domain-xss@example.com', 'zone' => 'client']);
        $this->seedRow(['user_id' => $user->id, 'domain' => self::XSS_SCRIPT]);
        $this->loginAs($user);

        $result = $this->withSession()->get('/api-history');
        $result->assertStatus(200);

        // Raw body string, not assertSee()/DOMParser — DOMDocument's
        // load/saveHTML round-trip renormalizes numeric character entities
        // inside attribute values, which would give a false negative/
        // positive independent of the app's real escaping (see the same
        // note in ApiKeysViewTest).
        $body = (string) $result->response()->getBody();
        $this->assertStringNotContainsString(self::XSS_SCRIPT, $body);
        $this->assertStringContainsString('&lt;script&gt;alert&#x28;1&#x29;', $body);
    }

    public function testClientPreviewIsNotFoundForAForeignRowButAdminCanSeeIt(): void
    {
        $admin = $this->seedWebUser(['email' => 'view-hist-admin2@example.com', 'zone' => 'global']);
        $userA = $this->seedWebUser(['email' => 'view-hist-a@example.com', 'zone' => 'client']);
        $userB = $this->seedWebUser(['email' => 'view-hist-b@example.com', 'zone' => 'client']);
        $rowB  = $this->seedRow(['user_id' => $userB->id]);

        $this->loginAs($userA);
        $foreign = $this->withSession()->get('/api-history/preview?id=' . $rowB);
        $foreign->assertStatus(404);
        $this->assertSame('failed', json_decode($foreign->getJSON(), true)['status']);

        session()->destroy();
        $this->loginAs($admin);
        $asAdmin = $this->withSession()->get('/admin/api-history/preview?id=' . $rowB);
        $asAdmin->assertStatus(200);
        $this->assertSame($rowB, (int) json_decode($asAdmin->getJSON(), true)['row']['id']);
    }

    public function testFilterAndSortValuesRepopulateInTheRenderedForm(): void
    {
        $user = $this->seedWebUser(['email' => 'view-hist-repop@example.com', 'zone' => 'client']);
        $this->seedRow(['user_id' => $user->id]);
        $this->loginAs($user);

        $result = $this->withSession()->get('/api-history?keyword=foo&status=1&sort_by=command&sort=asc');

        $result->assertStatus(200);
        $body = (string) $result->response()->getBody();
        $this->assertStringContainsString('id="keyword" name="keyword" value="foo"', $body);
        $this->assertStringContainsString('value="1" selected', $body); // status=Success option
        $this->assertStringContainsString('Command ▲', $body); // active sort column + direction arrow
    }

    public function testGuestIsRedirectedToLoginFromBothIndexViews(): void
    {
        $this->withSession(['basicAuthAuthenticated' => 1]);
        $this->get('/api-history')->assertRedirectTo('/login');

        $this->withSession(['basicAuthAuthenticated' => 1]);
        $this->get('/admin/api-history')->assertRedirectTo('/login');
    }

    public function testClientSessionIsBlockedFromAdminIndexByAdminGate(): void
    {
        $client = $this->seedWebUser(['email' => 'view-hist-gate@example.com', 'zone' => 'client']);
        $this->loginAs($client);

        $result = $this->withSession()->get('/admin/api-history');

        $result->assertRedirectTo('/dashboard');
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function seedRow(array $overrides = []): int
    {
        return model(ApiHistoryModel::class)->insert(array_merge([
            'env'          => 'TESTING',
            'service_name' => 'cnr',
            'service_id'   => 800,
            'command'      => 'StatusAccount',
            'domain'       => 'example.com',
            'params'       => '{"command":"StatusAccount"}',
            'response'     => '{"code":200}',
            'status'       => 1,
        ], $overrides), true);
    }
}
