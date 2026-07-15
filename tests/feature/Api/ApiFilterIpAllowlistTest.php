<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\Api\ApiKeyFixture;

/**
 * `ApiFilter::before()`'s per-key `allowed_ips` enforcement, exercised via
 * the CNR route (auth logic is shared, see CnrApiAuthTest). CI4's test
 * request harness doesn't populate `$_SERVER['REMOTE_ADDR']` on its own
 * (it falls through to the real superglobal, unset under a CLI test run),
 * so `setUp()` pins it to `127.0.0.1` via the `superglobals` service —
 * allowlists below are built around that fixed client IP.
 *
 * @internal
 */
final class ApiFilterIpAllowlistTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use ApiKeyFixture;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;

    protected function setUp(): void
    {
        parent::setUp();
        service('superglobals')->setServer('REMOTE_ADDR', '127.0.0.1');
    }

    public function testKeyWithoutAllowedIpsHasNoRestriction(): void
    {
        $key = $this->seedApiKey(1, 0, 800, ['allowed_ips' => '']);

        $result = $this->withHeaders(['Authorization' => 'Bearer ' . $key['token']])
            ->get('/api/cnr/testing');

        $result->assertStatus(400); // reaches the controller (missing command), not 401/403
    }

    public function testKeyWithAllowlistExcludingClientIpIsRejected(): void
    {
        $key = $this->seedApiKey(1, 0, 800, ['allowed_ips' => "10.0.0.0/8"]);

        $result = $this->withHeaders(['Authorization' => 'Bearer ' . $key['token']])
            ->get('/api/cnr/testing');

        $result->assertStatus(403);
        $result->assertJSONExact(['status' => 'error', 'message' => 'IP not allowed']);
    }

    public function testKeyWithAllowlistIncludingClientIpPasses(): void
    {
        $key = $this->seedApiKey(1, 0, 800, ['allowed_ips' => "10.0.0.0/8\n127.0.0.0/8"]);

        $result = $this->withHeaders(['Authorization' => 'Bearer ' . $key['token']])
            ->get('/api/cnr/testing');

        $result->assertStatus(400); // reaches the controller (missing command)
    }

    public function testKeyWithExactClientIpInAllowlistPasses(): void
    {
        $key = $this->seedApiKey(1, 0, 800, ['allowed_ips' => '127.0.0.1']);

        $result = $this->withHeaders(['Authorization' => 'Bearer ' . $key['token']])
            ->get('/api/cnr/testing');

        $result->assertStatus(400);
    }

    public function testRejectedIpDoesNotUpdateLastAccess(): void
    {
        $key = $this->seedApiKey(1, 0, 800, ['allowed_ips' => '10.0.0.0/8']);

        $this->withHeaders(['Authorization' => 'Bearer ' . $key['token']])
            ->get('/api/cnr/testing');

        $row = model(App\Models\ApiAccessKeyModel::class)->find($key['id']);
        $this->assertNull($row->last_access);
    }
}
