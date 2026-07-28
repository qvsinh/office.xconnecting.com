<?php

use App\Models\ApiKeyModel;
use App\Models\UserModel;
use App\Filters\ApiKeyFilter;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * `/api/v1/*` (`ApiKeyFilter`, `Api\V1\Me`), exercised
 * through real routing + filters — no session, no CSRF, Bearer-token auth
 * only: missing/bad token, read-scope key blocked from writes, the
 * `project_role()`/`can_edit_project()` IDOR guard against the API key's
 * owner (never a session), and `last_used_at` stamping. See
 * `kanban/processing/20260721-07-rest-api.md`.
 *
 * @internal
 */
final class ApiTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate = true;
    protected $refresh = true;
    protected $namespace;

    public function testMissingTokenIsUnauthorized(): void
    {
        $response = $this->get('/api/v1/me');

        $response->assertStatus(401);
        $this->assertSame('unauthorized', json_decode($response->getJSON(), true)['error']);
    }

    public function testBadTokenIsUnauthorized(): void
    {
        $response = $this->withHeaders(['Authorization' => 'Bearer sk_' . bin2hex(random_bytes(20))])
            ->get('/api/v1/me');

        $response->assertStatus(401);
    }

    /**
     * The write-scope guard lives in `ApiKeyFilter` and is exercised directly:
     * the API surface shrank to `GET /api/v1/me` when the projects/tasks module
     * was removed, so there is no longer a write route to POST at — but the guard
     * must still hold for the moment one is added.
     */
    public function testReadScopeKeyIsBlockedFromWriteVerbs(): void
    {
        $owner = $this->seedUser('read-owner@example.com');
        $token = $this->mintToken((int) $owner->id, 'read');

        $request = service('request');
        $request->setMethod('POST');
        $request->setHeader('Authorization', 'Bearer ' . $token);

        $response = (new ApiKeyFilter())->before($request);

        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('forbidden', json_decode((string) $response->getBody(), true)['error']);
    }

    public function testWriteScopeKeyPassesTheSameGuard(): void
    {
        $owner = $this->seedUser('write-owner@example.com');
        $token = $this->mintToken((int) $owner->id, 'read,write');

        $request = service('request');
        $request->setMethod('POST');
        $request->setHeader('Authorization', 'Bearer ' . $token);

        // null = "carry on", the filter's pass signal.
        $this->assertNull((new ApiKeyFilter())->before($request));
    }

    public function testMeReturnsTheKeyOwnerAndStampsLastUsedAt(): void
    {
        $owner  = $this->seedUser('me-owner@example.com');
        $result = model(ApiKeyModel::class)->createForUser((int) $owner->id, 'CLI', 'read');

        $this->assertNull($result['row']->last_used_at);

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $result['plain']])->get('/api/v1/me');

        $response->assertStatus(200);
        $json = json_decode($response->getJSON(), true);
        $this->assertSame((int) $owner->id, $json['data']['id']);
        $this->assertSame($owner->email, $json['data']['email']);
        $this->assertNotNull(model(ApiKeyModel::class)->find($result['row']->id)->last_used_at);
    }

    private function seedUser(string $email): object
    {
        $id = model(UserModel::class)->insert([
            'name'     => $email,
            'email'    => $email,
            'password' => 'irrelevant-hash',
            'zone'     => 'user',
            'status'   => 1,
        ]);

        return model(UserModel::class)->findUser((int) $id);
    }

    private function mintToken(int $userId, string $scopes): string
    {
        return model(ApiKeyModel::class)->createForUser($userId, 'Test key', $scopes)['plain'];
    }

}
