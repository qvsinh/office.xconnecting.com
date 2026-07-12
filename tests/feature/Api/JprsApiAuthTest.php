<?php

use App\Models\ApiAccessKeyModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\Api\ApiKeyFixture;

/**
 * Confirms `ApiFilter::before()` rejects the same way on the JPRS route as
 * on CNR (see `CnrApiAuthTest` for the full reject-scenario matrix) — no
 * JPRS service config/certs are needed since these all short-circuit before
 * `JprsApi::proxy()` runs.
 *
 * @internal
 */
final class JprsApiAuthTest extends CIUnitTestCase
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
        $result = $this->get('/api/jprs/testing');

        $result->assertStatus(401);
        $result->assertJSONExact(self::UNAUTHORIZED);
    }

    public function testDisabledKeyIsRejected(): void
    {
        $key = $this->seedApiKey(1, 0, 9801, ['is_enable' => 0]);

        $result = $this->withHeaders(['Authorization' => 'Bearer ' . $key['token']])
            ->get('/api/jprs/testing');

        $result->assertStatus(401);
        $result->assertJSONExact(self::UNAUTHORIZED);
    }

    public function testTestingKeyHittingLiveEndpointIsRejectedAsInvalidEndpoint(): void
    {
        $key = $this->seedApiKey(1, 0, 9801);

        $result = $this->withHeaders(['Authorization' => 'Bearer ' . $key['token']])
            ->get('/api/jprs/live');

        $result->assertStatus(401);
        $result->assertJSONExact(['status' => 'error', 'message' => 'Invalid endpoint']);
    }

    public function testLiveKeyHittingTestingEndpointIsRejectedAsInvalidEndpoint(): void
    {
        $key = $this->seedApiKey(1, 1, 9801);

        $result = $this->withHeaders(['Authorization' => 'Bearer ' . $key['token']])
            ->get('/api/jprs/testing');

        $result->assertStatus(401);
        $result->assertJSONExact(['status' => 'error', 'message' => 'Invalid endpoint']);
    }
}
