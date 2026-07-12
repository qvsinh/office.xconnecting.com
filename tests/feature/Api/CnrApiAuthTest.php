<?php

use App\Models\ApiAccessKeyModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\Api\ApiKeyFixture;

/**
 * Auth accept/reject paths shared by both proxy endpoints, exercised via
 * the CNR route. `ApiFilter::before()` behaves identically regardless of
 * which concrete proxy controller runs, since it short-circuits the request
 * with a 401 `ResponseInterface` before any controller is instantiated.
 *
 * @internal
 */
final class CnrApiAuthTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use ApiKeyFixture;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;

    private const UNAUTHORIZED = ['status' => 'error', 'message' => 'Unauthorized'];

    public function testMissingAuthorizationHeaderIsRejected(): void
    {
        $result = $this->get('/api/cnr/testing');

        $result->assertStatus(401);
        $result->assertJSONExact(self::UNAUTHORIZED);
    }

    public function testEmptyBearerTokenIsRejected(): void
    {
        $result = $this->withHeaders(['Authorization' => 'Bearer '])->get('/api/cnr/testing');

        $result->assertStatus(401);
        $result->assertJSONExact(self::UNAUTHORIZED);
    }

    public function testMalformedTokenIsRejected(): void
    {
        $result = $this->withHeaders(['Authorization' => 'Bearer not-a-real-token'])
            ->get('/api/cnr/testing');

        $result->assertStatus(401);
        $result->assertJSONExact(self::UNAUTHORIZED);
    }

    public function testDisabledKeyIsRejected(): void
    {
        $key = $this->seedApiKey(1, 0, 800, ['is_enable' => 0]);

        $result = $this->withHeaders(['Authorization' => 'Bearer ' . $key['token']])
            ->get('/api/cnr/testing');

        $result->assertStatus(401);
        $result->assertJSONExact(self::UNAUTHORIZED);
    }

    public function testUserIdMismatchIsRejected(): void
    {
        // Token encodes user_id=1, but the key row on file belongs to user_id=2.
        $key = $this->seedApiKey(1, 0, 800);
        model(ApiAccessKeyModel::class)->update($key['id'], ['user_id' => 2]);

        $result = $this->withHeaders(['Authorization' => 'Bearer ' . $key['token']])
            ->get('/api/cnr/testing');

        $result->assertStatus(401);
        $result->assertJSONExact(self::UNAUTHORIZED);
    }

    public function testServiceIdMismatchIsRejected(): void
    {
        // Token encodes service_id=800, but the key row on file is service_id=900.
        $key = $this->seedApiKey(1, 0, 800);
        model(ApiAccessKeyModel::class)->update($key['id'], ['service_id' => 900]);

        $result = $this->withHeaders(['Authorization' => 'Bearer ' . $key['token']])
            ->get('/api/cnr/testing');

        $result->assertStatus(401);
        $result->assertJSONExact(self::UNAUTHORIZED);
    }

    public function testTestingKeyHittingLiveEndpointIsRejectedAsInvalidEndpoint(): void
    {
        $key = $this->seedApiKey(1, 0, 800);

        $result = $this->withHeaders(['Authorization' => 'Bearer ' . $key['token']])
            ->get('/api/cnr/live');

        $result->assertStatus(401);
        $result->assertJSONExact(['status' => 'error', 'message' => 'Invalid endpoint']);
    }

    public function testLiveKeyHittingTestingEndpointIsRejectedAsInvalidEndpoint(): void
    {
        $key = $this->seedApiKey(1, 1, 800);

        $result = $this->withHeaders(['Authorization' => 'Bearer ' . $key['token']])
            ->get('/api/cnr/testing');

        $result->assertStatus(401);
        $result->assertJSONExact(['status' => 'error', 'message' => 'Invalid endpoint']);
    }

    public function testValidEnabledKeyMatchingEnvIsAccepted(): void
    {
        $key = $this->seedApiKey(1, 0, 800);

        // No command param -> reaches the controller (auth passes) and
        // returns the 400 "missing command" envelope instead of 401.
        $result = $this->withHeaders(['Authorization' => 'Bearer ' . $key['token']])
            ->get('/api/cnr/testing');

        $result->assertStatus(400);
        $result->assertJSONFragment(['status' => 'error']);
    }

    public function testLastAccessUpdatedOnAuthenticatedCall(): void
    {
        $key = $this->seedApiKey(1, 0, 800);

        $before = model(ApiAccessKeyModel::class)->find($key['id']);
        $this->assertNull($before->last_access);

        $this->withHeaders(['Authorization' => 'Bearer ' . $key['token']])
            ->get('/api/cnr/testing');

        $after = model(ApiAccessKeyModel::class)->find($key['id']);
        $this->assertNotNull($after->last_access);
    }
}
