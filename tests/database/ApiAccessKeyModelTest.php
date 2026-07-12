<?php

use App\Models\ApiAccessKeyModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * @internal
 */
final class ApiAccessKeyModelTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = true;

    public function testFindByAccessKeyAndTouchLastAccess(): void
    {
        $model = new ApiAccessKeyModel();
        $model->insert([
            'user_id'    => 1,
            'access_key' => 'test-access-key-123',
            'service_id' => 800,
            'is_enable'  => 1,
            'enable_log' => 1,
        ]);

        $row = $model->findByAccessKey('test-access-key-123');
        $this->assertNotNull($row);
        $this->assertSame(1, (int) $row->user_id);
        $this->assertNull($row->last_access);

        $model->touchLastAccess('test-access-key-123');
        $row = $model->findByAccessKey('test-access-key-123');
        $this->assertNotNull($row->last_access);
    }

    public function testFindByAccessKeyReturnsNullForUnknownKey(): void
    {
        $model = new ApiAccessKeyModel();
        $this->assertNull($model->findByAccessKey('does-not-exist'));
    }
}
