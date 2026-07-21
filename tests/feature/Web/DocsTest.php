<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\Web\WebAuthFixture;

/**
 * Route + rendering coverage for the in-app docs area (`Docs` controller —
 * see `kanban/processing/20260721-09-docs.md`). All three pages sit behind
 * `authFilter`; `docs/api` additionally carries the real `api/v1` base URL
 * and the `Bearer` auth-header hint so the copy-paste examples stay
 * accurate as the API surface changes.
 *
 * @internal
 */
final class DocsTest extends CIUnitTestCase
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

    public function testIndexRendersForAuthenticatedUser(): void
    {
        $user = $this->seedWebUser(['email' => 'docs-index@example.com', 'zone' => 'client']);
        $this->loginAs($user);

        $result = $this->withSession()->get('/docs');

        $result->assertStatus(200);
    }

    public function testGettingStartedRendersForAuthenticatedUser(): void
    {
        $user = $this->seedWebUser(['email' => 'docs-getting-started@example.com', 'zone' => 'client']);
        $this->loginAs($user);

        $result = $this->withSession()->get('/docs/getting-started');

        $result->assertStatus(200);
    }

    public function testApiRendersForAuthenticatedUserWithBaseUrlAndBearerHint(): void
    {
        $user = $this->seedWebUser(['email' => 'docs-api@example.com', 'zone' => 'client']);
        $this->loginAs($user);

        $result = $this->withSession()->get('/docs/api');

        $result->assertStatus(200);
        $body = (string) $result->response()->getBody();
        $this->assertStringContainsString(rtrim(base_url('api/v1'), '/'), $body);
        $this->assertStringContainsString('Bearer', $body);
    }

    public function testGuestIsRedirectedToLoginFromDocsIndex(): void
    {
        $this->withSession(['basicAuthAuthenticated' => 1]);

        $result = $this->get('/docs');

        $result->assertRedirectTo('/login');
    }

    public function testGuestIsRedirectedToLoginFromDocsGettingStarted(): void
    {
        $this->withSession(['basicAuthAuthenticated' => 1]);

        $result = $this->get('/docs/getting-started');

        $result->assertRedirectTo('/login');
    }

    public function testGuestIsRedirectedToLoginFromDocsApi(): void
    {
        $this->withSession(['basicAuthAuthenticated' => 1]);

        $result = $this->get('/docs/api');

        $result->assertRedirectTo('/login');
    }
}
