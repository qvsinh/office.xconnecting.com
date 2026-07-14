<?php

use App\Models\SiteVariableModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * `get_site_variable()` (app-wide read helper over `site_variable`) — hits
 * the real DB via SiteVariableModel, so this lives alongside the other
 * database-backed suites rather than tests/unit.
 *
 * @internal
 */
final class SettingHelperTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;

    protected function setUp(): void
    {
        parent::setUp();
        helper('setting');
    }

    public function testReturnsStoredValueWhenPresent(): void
    {
        model(SiteVariableModel::class)->insert(['name' => 'app_name', 'value' => 'ProxyV2']);

        $this->assertSame('ProxyV2', get_site_variable('app_name'));
    }

    public function testReturnsDefaultWhenNameUnknownOrSoftDeleted(): void
    {
        $this->assertSame('fallback', get_site_variable('unknown_setting', 'fallback'));
        $this->assertNull(get_site_variable('unknown_setting'));

        $model = model(SiteVariableModel::class);
        $id    = $model->insert(['name' => 'deleted_setting', 'value' => 'gone']);
        $model->delete($id);

        $this->assertSame('fallback', get_site_variable('deleted_setting', 'fallback'));
    }
}
