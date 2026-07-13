<?php

use App\Models\ApiAccessKeyModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\Web\WebAuthFixture;

/**
 * View-rendering coverage for the API Access Keys management UI
 * (`admin/api_access_keys/index.php` + `client/api_access_keys/index.php`),
 * exercised through the real HTTP stack. Controller/model logic (CRUD,
 * IDOR, key-gen, admin gate) is already covered by AdminApiKeysTest,
 * ClientApiKeysTest, and ApiAccessKeyModelTest — this suite closes the
 * remaining gap: does the view actually render, and is dynamic output
 * (owner name, description, the raw access key embedded for reveal/copy)
 * escaped correctly.
 *
 * @internal
 */
final class ApiKeysViewTest extends CIUnitTestCase
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

    public function testAdminIndexRendersTheAdminListView(): void
    {
        $admin = $this->seedWebUser(['email' => 'view-admin@example.com', 'zone' => 'global']);
        $this->loginAs($admin);

        $result = $this->withSession()->get('/admin/api-access-keys');

        $result->assertStatus(200);
        $result->assertSee('API access keys');
        $result->assertSee('+ Create key');
        $result->assertSeeElement('table');
    }

    public function testClientIndexRendersTheClientListView(): void
    {
        $user = $this->seedWebUser(['email' => 'view-client@example.com', 'zone' => 'client']);
        $this->loginAs($user);

        $result = $this->withSession()->get('/my-api-access-keys');

        $result->assertStatus(200);
        $result->assertSee('Your API access keys');
        $result->assertSeeElement('table');
    }

    public function testAdminViewEscapesOwnerNameAndDescriptionXss(): void
    {
        $admin  = $this->seedWebUser(['email' => 'view-admin2@example.com', 'zone' => 'global']);
        $target = $this->seedWebUser([
            'email' => 'xss-owner@example.com',
            'zone'  => 'client',
            'name'  => '<script>alert(1)</script>',
        ]);
        model(ApiAccessKeyModel::class)->createForUser($target, 800, '<script>alert(1)</script>');
        $this->loginAs($admin);

        $result = $this->withSession()->get('/admin/api-access-keys');
        $result->assertStatus(200);

        // Raw body string, not assertSee()/DOMParser — DOMDocument's
        // load/saveHTML round-trip renormalizes numeric character entities
        // inside attribute values (e.g. drops `&#x28;`), which would give a
        // false negative/positive independent of the app's real escaping.
        $body = (string) $result->response()->getBody();
        $this->assertStringNotContainsString('<script>alert(1)</script>', $body);
        // Matches the create-modal user <select> (`esc($u->name)`, plain
        // HTML context) — the owner name also appears inside the
        // apiKeysAdmin JSON payload, escaped more strictly there (see
        // testClientViewEscapesDescriptionXss for that form).
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $body);
    }

    public function testClientViewEscapesDescriptionXss(): void
    {
        $user = $this->seedWebUser(['email' => 'view-xss-client@example.com', 'zone' => 'client']);
        model(ApiAccessKeyModel::class)->createForUser($user, 800, '<script>alert(1)</script>');
        $this->loginAs($user);

        $result = $this->withSession()->get('/my-api-access-keys');
        $result->assertStatus(200);

        // Raw body string (see comment in the admin XSS test above for why
        // not assertSee()/DOMParser here).
        $body = (string) $result->response()->getBody();
        $this->assertStringNotContainsString('<script>alert(1)</script>', $body);
        // The client view only surfaces `description` inside the Alpine
        // `x-data="apiKeysClient(...)"` JSON payload (no plain esc('html')
        // occurrence like the admin user-dropdown), so json_encode() +
        // esc(..., 'attr') numeric-entity-escapes `(`, `)`, and the `/`
        // (json_encode's own `\/` escaping) on top of `<`/`>` — still safe,
        // just a stricter encoding than the plain HTML-escaped form.
        $this->assertStringContainsString('&lt;script&gt;alert&#x28;1&#x29;&lt;&#x5C;&#x2F;script&gt;', $body);
    }

    /**
     * The raw `access_key` MUST still appear in the page (reveal/copy needs
     * it) — but only inside the Alpine `x-data="apiKeysAdmin(...)"` JSON
     * payload, which is passed through `esc(json_encode(...), 'attr')`.
     * Prove both: the raw key is present, and it sits inside that
     * attribute (not interpolated elsewhere as raw HTML).
     */
    public function testAdminViewExposesRawAccessKeyOnlyWithinTheAlpineDataPayload(): void
    {
        $admin  = $this->seedWebUser(['email' => 'view-admin3@example.com', 'zone' => 'global']);
        $target = $this->seedWebUser(['email' => 'key-owner@example.com', 'zone' => 'client']);
        $accessKey = model(ApiAccessKeyModel::class)->createForUser($target, 800);
        $this->loginAs($admin);

        $result = $this->withSession()->get('/admin/api-access-keys');
        $result->assertStatus(200);

        $body = (string) $result->response()->getBody();
        $this->assertStringContainsString($accessKey, $body);

        // The JSON payload is HTML-attribute-escaped: a literal `"` from
        // json_encode() must appear as `&quot;` in the source, never
        // unescaped (which would break out of the x-data="..." attribute).
        $dataAttrStart = strpos($body, 'x-data="apiKeysAdmin(');
        $this->assertNotFalse($dataAttrStart, 'apiKeysAdmin Alpine component not found in rendered HTML.');
        $keyPos = strpos($body, $accessKey);
        $this->assertGreaterThan($dataAttrStart, $keyPos, 'access_key must be rendered inside the apiKeysAdmin x-data payload.');

        // Immediately after the access_key value inside the JSON, the
        // closing quote must be the escaped entity, not a bare `"`.
        $afterKey = substr($body, $keyPos + strlen($accessKey), 6);
        $this->assertStringStartsWith('&quot;', $afterKey);
    }

    public function testClientViewShowsTheTwentyKeyQuota(): void
    {
        $user = $this->seedWebUser(['email' => 'view-quota@example.com', 'zone' => 'client']);
        $this->loginAs($user);

        $result = $this->withSession()->get('/my-api-access-keys');

        $result->assertStatus(200);
        $result->assertSee(', ' . ApiAccessKeyModel::MAX_KEYS_PER_USER . ')');
    }

    public function testGuestIsRedirectedToLoginFromAdminIndex(): void
    {
        $this->withSession(['basicAuthAuthenticated' => 1]);

        $result = $this->get('/admin/api-access-keys');

        $result->assertRedirectTo('/login');
    }

    public function testGuestIsRedirectedToLoginFromClientIndex(): void
    {
        $this->withSession(['basicAuthAuthenticated' => 1]);

        $result = $this->get('/my-api-access-keys');

        $result->assertRedirectTo('/login');
    }

    public function testClientSessionIsBlockedFromAdminIndexByAdminGate(): void
    {
        $client = $this->seedWebUser(['email' => 'view-client-gate@example.com', 'zone' => 'client']);
        $this->loginAs($client);

        $result = $this->withSession()->get('/admin/api-access-keys');

        // adminFilter redirects non-admin sessions away (see AdminFilter::before()).
        $result->assertRedirectTo('/dashboard');
    }
}
