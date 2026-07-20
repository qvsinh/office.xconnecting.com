<?php

use App\Models\ApiKeyModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\Web\WebAuthFixture;

/**
 * Personal API keys (`Account\ApiKeys`): create returns the plaintext token
 * exactly once and stores only prefix+hash, index() never exposes the hash,
 * and revoke() is owner-scoped (IDOR guard) — mirrors AccountTest's
 * conventions.
 *
 * @internal
 */
final class ApiKeysTest extends CIUnitTestCase
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

    public function testGuestIsRedirectedToLoginFromIndex(): void
    {
        $this->withSession(['basicAuthAuthenticated' => 1]);

        $result = $this->get('/account/api-keys');

        $result->assertRedirectTo('/login');
    }

    public function testCreateReturnsPlaintextTokenOnceAndStoresOnlyPrefixAndHash(): void
    {
        $user = $this->seedWebUser(['email' => 'apikey-create@example.com']);
        $this->loginAs($user);

        $result = $this->withSession()->post('/account/api-keys/create', $this->withCsrf([
            'name' => 'my-agent',
        ]));

        $result->assertStatus(200);
        $json = json_decode($result->getJSON(), true);
        $this->assertSame('success', $json['status']);
        $this->assertStringStartsWith('sk_', $json['token']);

        $row = model(ApiKeyModel::class)->find($json['key']['id']);
        $this->assertNotNull($row);
        $this->assertSame(substr($json['token'], 0, 12), $row->token_prefix);
        $this->assertNotSame($json['token'], $row->token_hash);
        $this->assertSame(hash('sha256', $json['token']), $row->token_hash);
    }

    public function testCreateDefaultsScopeToReadAndAppliesExpiresDays(): void
    {
        $user = $this->seedWebUser(['email' => 'apikey-expiry@example.com']);
        $this->loginAs($user);

        $result = $this->withSession()->post('/account/api-keys/create', $this->withCsrf([
            'name'         => 'agent-with-expiry',
            'expires_days' => '30',
        ]));

        $result->assertStatus(200);
        $json = json_decode($result->getJSON(), true);
        $row  = model(ApiKeyModel::class)->find($json['key']['id']);

        $this->assertSame('read', $row->scopes);
        $this->assertNotNull($row->expires_at);
        $this->assertGreaterThan(date('Y-m-d H:i:s'), $row->expires_at);
    }

    public function testCreateRejectsEmptyName(): void
    {
        $user = $this->seedWebUser(['email' => 'apikey-empty@example.com']);
        $this->loginAs($user);

        $result = $this->withSession()->post('/account/api-keys/create', $this->withCsrf(['name' => '']));

        $result->assertStatus(400);
        $json = json_decode($result->getJSON(), true);
        $this->assertSame('failed', $json['status']);
    }

    public function testCreateRejectsInvalidScope(): void
    {
        $user = $this->seedWebUser(['email' => 'apikey-badscope@example.com']);
        $this->loginAs($user);

        $result = $this->withSession()->post('/account/api-keys/create', $this->withCsrf([
            'name'   => 'bad-scope',
            'scopes' => 'admin',
        ]));

        $result->assertStatus(400);
    }

    public function testIndexListsOwnKeysAndNeverExposesTheHash(): void
    {
        $user = $this->seedWebUser(['email' => 'apikey-index@example.com']);
        $this->loginAs($user);

        model(ApiKeyModel::class)->createForUser($user->id, 'listed-key');

        $result = $this->withSession()->get('/account/api-keys');

        $result->assertStatus(200);
        $result->assertDontSee('token_hash');

        $row = model(ApiKeyModel::class)->where('user_id', $user->id)->first();
        $result->assertDontSee($row->token_hash);
    }

    public function testRevokeSetsRevokedAtForOwnKey(): void
    {
        $user = $this->seedWebUser(['email' => 'apikey-revoke@example.com']);
        $this->loginAs($user);

        $created = model(ApiKeyModel::class)->createForUser($user->id, 'to-revoke');

        $result = $this->withSession()->post('/account/api-keys/revoke', $this->withCsrf([
            'id' => $created['row']->id,
        ]));

        $result->assertStatus(200);
        $json = json_decode($result->getJSON(), true);
        $this->assertSame('success', $json['status']);

        $row = model(ApiKeyModel::class)->find($created['row']->id);
        $this->assertNotNull($row->revoked_at);
    }

    public function testRevokeOfAnotherUsersKeyReturns404AndLeavesItUnrevoked(): void
    {
        $owner    = $this->seedWebUser(['email' => 'apikey-owner@example.com']);
        $attacker = $this->seedWebUser(['email' => 'apikey-attacker@example.com']);
        $this->loginAs($attacker);

        $created = model(ApiKeyModel::class)->createForUser($owner->id, 'not-yours');

        $result = $this->withSession()->post('/account/api-keys/revoke', $this->withCsrf([
            'id' => $created['row']->id,
        ]));

        $result->assertStatus(404);
        $json = json_decode($result->getJSON(), true);
        $this->assertSame('failed', $json['status']);

        $row = model(ApiKeyModel::class)->find($created['row']->id);
        $this->assertNull($row->revoked_at);
    }

    public function testRevokeMissingIdIsRejected(): void
    {
        $user = $this->seedWebUser(['email' => 'apikey-noid@example.com']);
        $this->loginAs($user);

        $result = $this->withSession()->post('/account/api-keys/revoke', $this->withCsrf([]));

        $result->assertStatus(400);
    }
}
