<?php

use App\Models\ServerModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * @internal
 */
final class ServerModelTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;

    public function testFindServerReturnsServer(): void
    {
        $model = new ServerModel();
        $id    = $model->insert(['name' => 'ns1', 'ip' => '1.2.3.4', 'status' => 1]);

        $server = $model->findServer($id);

        $this->assertNotNull($server);
        $this->assertSame('ns1', $server->name);
    }

    public function testFindServerReturnsNullForUnknownId(): void
    {
        $model = new ServerModel();

        $this->assertNull($model->findServer(999999));
    }

    public function testSearchAdminFiltersByKeywordAcrossNameAndIp(): void
    {
        $model = new ServerModel();
        $model->insert(['name' => 'alpha-node', 'ip' => '10.0.0.1', 'status' => 1]);
        $model->insert(['name' => 'beta-node', 'ip' => '10.0.0.2', 'status' => 1]);
        $model->insert(['name' => 'gamma-node', 'ip' => '192.168.1.5', 'status' => 1]);

        $byName = $model->searchAdmin(['keyword' => 'alpha']);
        $this->assertCount(1, $byName);
        $this->assertSame('alpha-node', $byName[0]->name);

        $byIp = $model->searchAdmin(['keyword' => '192.168.1.5']);
        $this->assertCount(1, $byIp);
        $this->assertSame('gamma-node', $byIp[0]->name);
    }

    public function testSearchAdminFiltersByStatus(): void
    {
        $model = new ServerModel();
        $model->insert(['name' => 'active-node', 'ip' => '10.0.0.1', 'status' => 1]);
        $model->insert(['name' => 'inactive-node', 'ip' => '10.0.0.2', 'status' => 0]);

        $active = $model->searchAdmin(['status' => 1]);
        $this->assertCount(1, $active);
        $this->assertSame('active-node', $active[0]->name);

        $inactive = $model->searchAdmin(['status' => 0]);
        $this->assertCount(1, $inactive);
        $this->assertSame('inactive-node', $inactive[0]->name);
    }

    public function testSearchAdminRejectsArbitrarySortColumnAndFallsBackToStatus(): void
    {
        $model = new ServerModel();
        $model->insert(['name' => 'z-node', 'ip' => '10.0.0.9', 'status' => 0]);
        $model->insert(['name' => 'a-node', 'ip' => '10.0.0.1', 'status' => 1]);

        $rows = $model->searchAdmin([], 'password; DROP TABLE servers;', 'desc');

        // Falls back to default sort (status desc, id desc) rather than
        // erroring or injecting the malicious column into the query.
        $this->assertSame('a-node', $rows[0]->name);
    }

    public function testCountAdminMatchesUnpaginatedRowCountForSameFilters(): void
    {
        $model = new ServerModel();
        for ($i = 0; $i < 5; $i++) {
            $model->insert(['name' => "node-{$i}", 'ip' => "10.0.0.{$i}", 'status' => 1]);
        }

        $filters = ['status' => 1];
        $page    = $model->searchAdmin($filters, 'id', 'asc', 2, 0);

        $this->assertCount(2, $page);
        $this->assertSame(5, $model->countAdmin($filters));
    }

    public function testSearchAdminExcludesSoftDeletedRows(): void
    {
        $model = new ServerModel();
        $id    = $model->insert(['name' => 'deleted-node', 'ip' => '10.0.0.1', 'status' => 1]);
        $model->delete($id);

        $this->assertSame([], $model->searchAdmin(['keyword' => 'deleted-node']));
        $this->assertSame(0, $model->countAdmin(['keyword' => 'deleted-node']));
    }
}
