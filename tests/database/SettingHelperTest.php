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

    public function testSiteNameReflectsStoredValueAndFallsBackWhenBlank(): void
    {
        $model = model(SiteVariableModel::class);

        // Default (seeded or fallback) is the same value either way.
        $this->assertSame('CI4 Starter', site_name());

        // Upsert (a `site_name` row may already be seeded by migration).
        $existing = $model->where('name', 'site_name')->first();
        $id       = $existing !== null ? (int) $existing->id : (int) $model->insert(['name' => 'site_name', 'value' => 'x'], true);

        // ...reflect a stored value...
        $model->update($id, ['value' => 'Custom Brand']);
        $this->assertSame('Custom Brand', site_name());

        // ...and fall back again when it's blanked.
        $model->update($id, ['value' => '']);
        $this->assertSame('CI4 Starter', site_name());
    }
}
