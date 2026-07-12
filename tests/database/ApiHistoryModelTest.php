<?php

use App\Models\ApiHistoryModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * @internal
 */
final class ApiHistoryModelTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = true;

    public function testInsertAndScopedRead(): void
    {
        $model = new ApiHistoryModel();
        $model->insert([
            'env'          => 'testing',
            'service_name' => 'cnr',
            'service_id'   => 800,
            'user_id'      => 1,
            'command'      => 'StatusAccount',
            'domain'       => null,
            'params'       => '{"command":"StatusAccount"}',
            'response'     => '{"code":200}',
            'status'       => 1,
        ]);

        $rows = $model->where('service_name', 'cnr')->where('service_id', 800)->findAll();
        $this->assertCount(1, $rows);
        $this->assertSame('StatusAccount', $rows[0]['command']);
    }
}
