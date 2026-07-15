<?php

use App\Models\ApiAccessKeyModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\Api\ApiKeyFixture;
use Tests\Support\Web\WebAuthFixture;

/**
 * Client-zone API connection self-test (`Client\ApiConnectionTest`), run
 * through real routing + filters (`authFilter`). The actual probe now runs
 * as a `fetch()` from the caller's own browser against the public
 * `/api/{provider}/{env}/` proxy — there is no server-side `run()` step
 * left to test here, only that `index()` renders with the right key scope
 * (own keys only, never another user's) and the zone is gated correctly.
 *
 * @internal
 */
final class ClientApiConnectionTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use WebAuthFixture;
    use ApiKeyFixture;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;

    private const CNR_SERVICE_ID = 800;

    protected function tearDown(): void
    {
        parent::tearDown();
        session()->destroy();
    }

    public function testIndexRendersWithOwnKeyList(): void
    {
        $user = $this->seedWebUser(['email' => 'api-test-client-index@example.com', 'zone' => 'client']);
        $this->seedApiKey($user->id, 0, self::CNR_SERVICE_ID);

        $this->loginAs($user);
        $result = $this->withSession()->get('/api-test');

        $result->assertStatus(200);
    }

    public function testIndexKeyListIsScopedToOwnKeysOnly(): void
    {
        $owner   = $this->seedWebUser(['email' => 'api-test-owner-scope@example.com', 'zone' => 'client']);
        $another = $this->seedWebUser(['email' => 'api-test-another-scope@example.com', 'zone' => 'client']);
        $mine    = $this->seedApiKey($owner->id, 0, self::CNR_SERVICE_ID);
        $theirs  = $this->seedApiKey($another->id, 0, self::CNR_SERVICE_ID);

        $this->loginAs($owner);
        $this->withSession()->get('/api-test')->assertStatus(200);

        $keys       = model(ApiAccessKeyModel::class)->listForConnectionTest((int) $owner->id);
        $accessKeys = array_column($keys, 'access_key');
        $this->assertContains($mine['token'], $accessKeys);
        $this->assertNotContains($theirs['token'], $accessKeys);
    }

    public function testGuestIsRedirectedToLogin(): void
    {
        $this->withSession(['basicAuthAuthenticated' => 1]);
        $this->get('/api-test')->assertRedirectTo('/login');
    }
}
