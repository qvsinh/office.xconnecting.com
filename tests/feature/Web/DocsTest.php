<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\Web\WebAuthFixture;

/**
 * Route + rendering coverage for the in-app docs area (`Docs` controller). Both
 * pages sit behind `authFilter`.
 *
 * The `docs/api` page was removed with the projects/tasks module — the REST API
 * is down to `GET /api/v1/me`, which needs no page of its own.
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
        $user = $this->seedWebUser(['email' => 'docs-index@example.com', 'zone' => 'user']);
        $this->loginAs($user);

        $result = $this->withSession()->get('/docs');

        $result->assertStatus(200);
    }

    public function testGettingStartedRendersForAuthenticatedUser(): void
    {
        $user = $this->seedWebUser(['email' => 'docs-getting-started@example.com', 'zone' => 'user']);
        $this->loginAs($user);

        $result = $this->withSession()->get('/docs/getting-started');

        $result->assertStatus(200);
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

}
