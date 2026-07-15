<?php

use App\Models\ApiAccessKeyModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\Web\WebAuthFixture;

/**
 * Admin CRUD over `users_api_access_keys` (`Admin\ApiAccessKeys`), focused
 * on the `allowed_ips` IP allowlist field added on create/update.
 *
 * @internal
 */
final class AdminApiAccessKeysTest extends CIUnitTestCase
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

    public function testCreateWithValidAllowedIpsStoresNormalizedList(): void
    {
        [$admin, $target] = $this->seedAdminAndTargetUser();
        $this->loginAs($admin);

        $result = $this->withSession()->post('/admin/api-access-keys/create', $this->withCsrf([
            'user_id'     => $target->id,
            'service_id'  => 800,
            'allowed_ips' => "192.168.1.1\n10.0.0.0/8, 2001:db8::/32",
        ]));

        $result->assertStatus(200);
        $json = json_decode($result->getJSON(), true);
        $this->assertSame('success', $json['status']);

        $row = model(ApiAccessKeyModel::class)->findByAccessKey($json['access_key']);
        $this->assertSame("192.168.1.1\n10.0.0.0/8\n2001:db8::/32", $row->allowed_ips);
    }

    public function testCreateWithInvalidIpRuleIsRejected(): void
    {
        [$admin, $target] = $this->seedAdminAndTargetUser();
        $this->loginAs($admin);

        $result = $this->withSession()->post('/admin/api-access-keys/create', $this->withCsrf([
            'user_id'     => $target->id,
            'service_id'  => 800,
            'allowed_ips' => 'not-an-ip',
        ]));

        $result->assertStatus(400);
        $json = json_decode($result->getJSON(), true);
        $this->assertSame('failed', $json['status']);
        $this->assertSame(0, model(ApiAccessKeyModel::class)->countForUser((int) $target->id));
    }

    public function testUpdateWithValidAllowedIpsStoresNormalizedList(): void
    {
        [$admin, $target] = $this->seedAdminAndTargetUser();
        $keyModel  = model(ApiAccessKeyModel::class);
        $accessKey = $keyModel->createForUser($target, 800);
        $key       = $keyModel->findByAccessKey($accessKey);

        $this->loginAs($admin);
        $result = $this->withSession()->post('/admin/api-access-keys/update', $this->withCsrf([
            'id'          => $key->id,
            'allowed_ips' => '203.0.113.5/32',
        ]));

        $result->assertStatus(200);
        $this->assertSame('203.0.113.5/32', $keyModel->find($key->id)->allowed_ips);
    }

    public function testUpdateWithInvalidIpRuleIsRejectedAndLeavesRowUnchanged(): void
    {
        [$admin, $target] = $this->seedAdminAndTargetUser();
        $keyModel  = model(ApiAccessKeyModel::class);
        $accessKey = $keyModel->createForUser($target, 800, '', '203.0.113.5/32');
        $key       = $keyModel->findByAccessKey($accessKey);

        $this->loginAs($admin);
        $result = $this->withSession()->post('/admin/api-access-keys/update', $this->withCsrf([
            'id'          => $key->id,
            'allowed_ips' => '999.999.999.999',
        ]));

        $result->assertStatus(400);
        $this->assertSame('203.0.113.5/32', $keyModel->find($key->id)->allowed_ips);
    }

    /**
     * @return array{0: object, 1: object} [admin user, target user]
     */
    private function seedAdminAndTargetUser(): array
    {
        $admin  = $this->seedWebUser(['email' => 'admin-ip@example.com', 'zone' => 'global']);
        $target = $this->seedWebUser(['email' => 'target-ip@example.com', 'zone' => 'client']);

        return [$admin, $target];
    }
}
