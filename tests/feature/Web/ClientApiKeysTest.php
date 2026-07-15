<?php

use App\Models\ApiAccessKeyModel;
use CodeIgniter\Config\Services;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\Api\FakeCurlRequest;
use Tests\Support\Api\FakeCurlResponse;
use Tests\Support\Web\WebAuthFixture;

/**
 * Self-service API access key management (`Client\ApiAccessKeys`), scoped
 * strictly to `session('userWorking')`. Covers the happy path, the 20-key
 * cap, and — as a required gate — explicit IDOR/ownership tests: user A
 * must never be able to read, toggle, log-toggle, or delete user B's key,
 * and an injected `user_id` in the create payload must never be honoured.
 *
 * @internal
 */
final class ClientApiKeysTest extends CIUnitTestCase
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
        Services::resetSingle('curlrequest');
        session()->destroy();
    }

    public function testUserCanCreateToggleLogAndRemoveTheirOwnKey(): void
    {
        $user = $this->seedWebUser(['email' => 'self@example.com', 'zone' => 'client', 'env' => 'TESTING']);
        $this->loginAs($user);

        $create = $this->withSession()->post('/my-api-access-keys/create', $this->withCsrf([
            'service_id' => 800,
        ]));

        $create->assertStatus(200);
        $json = json_decode($create->getJSON(), true);
        $this->assertSame('success', $json['status']);
        $this->assertNotEmpty($json['access_key']);

        $keyModel = model(ApiAccessKeyModel::class);
        $row      = $keyModel->findByAccessKey($json['access_key']);
        $this->assertNotNull($row);
        $this->assertSame((int) $user->id, (int) $row->user_id);
        $this->assertSame(0, (int) $row->user_status); // TESTING -> 0

        $toggle = $this->withSession()->post('/my-api-access-keys/toggle', $this->withCsrf([
            'id'        => $row->id,
            'is_enable' => 0,
        ]));
        $toggle->assertStatus(200);
        $this->assertSame(0, (int) $keyModel->find($row->id)->is_enable);

        $toggleLog = $this->withSession()->post('/my-api-access-keys/toggle-log', $this->withCsrf([
            'id'         => $row->id,
            'enable_log' => 0,
        ]));
        $toggleLog->assertStatus(200);
        $this->assertSame(0, (int) $keyModel->find($row->id)->enable_log);

        $remove = $this->withSession()->post('/my-api-access-keys/remove', $this->withCsrf(['id' => $row->id]));
        $remove->assertStatus(200);
        $this->assertNull($keyModel->find($row->id));
    }

    public function testCreateIgnoresAnInjectedUserIdAndAlwaysUsesTheSessionUser(): void
    {
        $me       = $this->seedWebUser(['email' => 'me@example.com', 'zone' => 'client']);
        $stranger = $this->seedWebUser(['email' => 'stranger@example.com', 'zone' => 'client']);
        $this->loginAs($me);

        $create = $this->withSession()->post('/my-api-access-keys/create', $this->withCsrf([
            'service_id' => 800,
            'user_id'    => $stranger->id, // must be ignored
        ]));

        $create->assertStatus(200);
        $json = json_decode($create->getJSON(), true);
        $row  = model(ApiAccessKeyModel::class)->findByAccessKey($json['access_key']);

        $this->assertSame((int) $me->id, (int) $row->user_id);
        $this->assertNotSame((int) $stranger->id, (int) $row->user_id);
    }

    public function testCreateIsRejectedAtTheTwentyKeyCap(): void
    {
        $user     = $this->seedWebUser(['email' => 'capped@example.com', 'zone' => 'client']);
        $keyModel = model(ApiAccessKeyModel::class);

        // Seed dummy rows directly (unique fake tokens) rather than minting
        // real ones in a tight loop — the real encode includes
        // `random_int(1, 1000)`, so 20 real mints for the same
        // user/env/service within one second carries a non-trivial
        // birthday-collision chance on the unique `access_key` column and
        // would make this test flaky (see ApiAccessKeyModelTest for the
        // same reasoning at the model level).
        for ($i = 0; $i < ApiAccessKeyModel::MAX_KEYS_PER_USER; $i++) {
            $keyModel->insert([
                'user_id'    => $user->id,
                'access_key' => 'seed-key-' . $user->id . '-' . $i,
                'service_id' => 800,
                'is_enable'  => 1,
                'enable_log' => 1,
            ]);
        }

        $this->loginAs($user);
        $result = $this->withSession()->post('/my-api-access-keys/create', $this->withCsrf([
            'service_id' => 800,
        ]));

        $result->assertStatus(400);
        $json = json_decode($result->getJSON(), true);
        $this->assertSame('failed', $json['status']);
        $this->assertSame(ApiAccessKeyModel::MAX_KEYS_PER_USER, $keyModel->countForUser((int) $user->id));
    }

    public function testUserBCannotToggleUserAsKey(): void
    {
        [$userA, $userB, $keyA] = $this->seedTwoUsersAndAKeyForA();

        $this->loginAs($userB);
        $result = $this->withSession()->post('/my-api-access-keys/toggle', $this->withCsrf([
            'id'        => $keyA->id,
            'is_enable' => 0,
        ]));

        $result->assertStatus(404);
        $json = json_decode($result->getJSON(), true);
        $this->assertSame('failed', $json['status']);

        $unchanged = model(ApiAccessKeyModel::class)->find($keyA->id);
        $this->assertSame(1, (int) $unchanged->is_enable);
        $this->assertSame((int) $userA->id, (int) $unchanged->user_id);
    }

    public function testUserBCannotToggleLogOnUserAsKey(): void
    {
        [$userA, $userB, $keyA] = $this->seedTwoUsersAndAKeyForA();

        $this->loginAs($userB);
        $result = $this->withSession()->post('/my-api-access-keys/toggle-log', $this->withCsrf([
            'id'         => $keyA->id,
            'enable_log' => 0,
        ]));

        $result->assertStatus(404);

        $unchanged = model(ApiAccessKeyModel::class)->find($keyA->id);
        $this->assertSame(1, (int) $unchanged->enable_log);
    }

    public function testUserBCannotRemoveUserAsKeyAndItStillExistsAfterward(): void
    {
        [$userA, $userB, $keyA] = $this->seedTwoUsersAndAKeyForA();

        $this->loginAs($userB);
        $result = $this->withSession()->post('/my-api-access-keys/remove', $this->withCsrf(['id' => $keyA->id]));

        $result->assertStatus(404);

        $this->assertNotNull(model(ApiAccessKeyModel::class)->find($keyA->id));
    }

    public function testUserBDoesNotSeeUserAsKeyInListForUser(): void
    {
        [$userA, $userB, $keyA] = $this->seedTwoUsersAndAKeyForA();

        $items = model(ApiAccessKeyModel::class)->listForUser((int) $userB->id);

        $this->assertCount(0, $items);
    }

    public function testCreateWithValidAllowedIpsStoresNormalizedList(): void
    {
        $user = $this->seedWebUser(['email' => 'ip-create@example.com', 'zone' => 'client']);
        $this->loginAs($user);

        $create = $this->withSession()->post('/my-api-access-keys/create', $this->withCsrf([
            'service_id'  => 800,
            'allowed_ips' => "192.168.1.1, 10.0.0.0/8\n192.168.1.1",
        ]));

        $create->assertStatus(200);
        $json = json_decode($create->getJSON(), true);
        $row  = model(ApiAccessKeyModel::class)->findByAccessKey($json['access_key']);

        $this->assertSame("192.168.1.1\n10.0.0.0/8", $row->allowed_ips);
    }

    public function testCreateWithInvalidAllowedIpsIsRejected(): void
    {
        $user = $this->seedWebUser(['email' => 'ip-create-bad@example.com', 'zone' => 'client']);
        $this->loginAs($user);

        $result = $this->withSession()->post('/my-api-access-keys/create', $this->withCsrf([
            'service_id'  => 800,
            'allowed_ips' => 'garbage-rule',
        ]));

        $result->assertStatus(400);
        $this->assertSame(0, model(ApiAccessKeyModel::class)->countForUser((int) $user->id));
    }

    public function testUpdateAllowedIpsHappyPath(): void
    {
        $user      = $this->seedWebUser(['email' => 'ip-update@example.com', 'zone' => 'client']);
        $keyModel  = model(ApiAccessKeyModel::class);
        $accessKey = $keyModel->createForUser($user, 800);
        $key       = $keyModel->findByAccessKey($accessKey);

        $this->loginAs($user);
        $result = $this->withSession()->post('/my-api-access-keys/allowed-ips', $this->withCsrf([
            'id'          => $key->id,
            'allowed_ips' => '203.0.113.0/24',
        ]));

        $result->assertStatus(200);
        $this->assertSame('203.0.113.0/24', $keyModel->find($key->id)->allowed_ips);
    }

    public function testUpdateAllowedIpsRejectsInvalidRule(): void
    {
        $user      = $this->seedWebUser(['email' => 'ip-update-bad@example.com', 'zone' => 'client']);
        $keyModel  = model(ApiAccessKeyModel::class);
        $accessKey = $keyModel->createForUser($user, 800, '', '203.0.113.0/24');
        $key       = $keyModel->findByAccessKey($accessKey);

        $this->loginAs($user);
        $result = $this->withSession()->post('/my-api-access-keys/allowed-ips', $this->withCsrf([
            'id'          => $key->id,
            'allowed_ips' => 'not-an-ip/99',
        ]));

        $result->assertStatus(400);
        $this->assertSame('203.0.113.0/24', $keyModel->find($key->id)->allowed_ips);
    }

    public function testUpdateAllowedIpsRejectsAnotherUsersKeyWith404(): void
    {
        [$userA, $userB, $keyA] = $this->seedTwoUsersAndAKeyForA();

        $this->loginAs($userB);
        $result = $this->withSession()->post('/my-api-access-keys/allowed-ips', $this->withCsrf([
            'id'          => $keyA->id,
            'allowed_ips' => '203.0.113.0/24',
        ]));

        $result->assertStatus(404);
        $this->assertSame('', (string) model(ApiAccessKeyModel::class)->find($keyA->id)->allowed_ips);
    }

    public function testGuestIsRedirectedToLoginFromSelfServiceMutation(): void
    {
        $this->withSession(['basicAuthAuthenticated' => 1]);

        $result = $this->post('/my-api-access-keys/create', $this->withCsrf([
            'service_id' => 800,
        ]));

        $result->assertRedirectTo('/login');
    }

    /**
     * Cross-contract: a key minted through the self-service create endpoint
     * must authenticate a real `/api/cnr` call through `ApiFilter` exactly
     * like one seeded directly — proves both create paths (admin + client)
     * share `ApiAccessKeyModel::createForUser()` and mint compatible tokens.
     */
    public function testAUiMintedKeyAuthenticatesThroughApiFilter(): void
    {
        $user = $this->seedWebUser(['email' => 'proof@example.com', 'zone' => 'client', 'env' => 'TESTING']);
        $this->loginAs($user);

        $create = $this->withSession()->post('/my-api-access-keys/create', $this->withCsrf([
            'service_id' => 800,
        ]));
        $accessKey = json_decode($create->getJSON(), true)['access_key'];

        // The web session must not leak into the stateless API call.
        session()->destroy();

        $fake = new FakeCurlRequest();
        $fake->queue(new FakeCurlResponse("code=200\ndescription=Command completed successfully\n"));
        Services::injectMock('curlrequest', $fake);

        $result = $this->withHeaders(['Authorization' => 'Bearer ' . $accessKey])
            ->get('/api/cnr/testing?command=StatusAccount');

        $result->assertStatus(200);
        $json = json_decode($result->getJSON(), true);
        $this->assertSame('success', $json['status']);
    }

    /**
     * @return array{0: object, 1: object, 2: object} [userA, userB, keyA row]
     */
    private function seedTwoUsersAndAKeyForA(): array
    {
        $userA = $this->seedWebUser(['email' => 'user-a@example.com', 'zone' => 'client']);
        $userB = $this->seedWebUser(['email' => 'user-b@example.com', 'zone' => 'client']);

        $keyModel = model(ApiAccessKeyModel::class);
        $accessKey = $keyModel->createForUser($userA, 800);
        $keyA      = $keyModel->findByAccessKey($accessKey);

        return [$userA, $userB, $keyA];
    }
}
