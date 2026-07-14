<?php

use App\Models\SiteVariableModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * @internal
 */
final class SiteVariableModelTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;

    public function testFindVariableReturnsVariable(): void
    {
        $model = new SiteVariableModel();
        $id    = $model->insert(['name' => 'app_name', 'value' => 'ProxyV2']);

        $variable = $model->findVariable($id);

        $this->assertNotNull($variable);
        $this->assertSame('app_name', $variable->name);
    }

    public function testFindVariableReturnsNullForUnknownId(): void
    {
        $model = new SiteVariableModel();

        $this->assertNull($model->findVariable(999999));
    }

    public function testGetValueReturnsStoredValue(): void
    {
        $model = new SiteVariableModel();
        $model->insert(['name' => 'app_name', 'value' => 'ProxyV2']);

        $this->assertSame('ProxyV2', $model->getValue('app_name'));
    }

    public function testGetValueReturnsDefaultWhenUnknown(): void
    {
        $model = new SiteVariableModel();

        $this->assertSame('fallback', $model->getValue('missing_name', 'fallback'));
        $this->assertNull($model->getValue('missing_name'));
    }

    public function testGetValueReturnsDefaultWhenSoftDeleted(): void
    {
        $model = new SiteVariableModel();
        $id    = $model->insert(['name' => 'app_name', 'value' => 'ProxyV2']);
        $model->delete($id);

        $this->assertSame('fallback', $model->getValue('app_name', 'fallback'));
    }

    public function testSearchAdminFiltersByKeywordAcrossNameValueDescription(): void
    {
        $model = new SiteVariableModel();
        $model->insert(['name' => 'app_name', 'value' => 'ProxyV2', 'description' => 'App display name']);
        $model->insert(['name' => 'email_support', 'value' => 'support@example.com', 'description' => 'Support inbox']);

        $byName = $model->searchAdmin(['keyword' => 'app_name']);
        $this->assertCount(1, $byName);
        $this->assertSame('app_name', $byName[0]->name);

        $byValue = $model->searchAdmin(['keyword' => 'support@example.com']);
        $this->assertCount(1, $byValue);
        $this->assertSame('email_support', $byValue[0]->name);

        $byDescription = $model->searchAdmin(['keyword' => 'Support inbox']);
        $this->assertCount(1, $byDescription);
        $this->assertSame('email_support', $byDescription[0]->name);
    }

    public function testSearchAdminRejectsArbitrarySortColumnAndFallsBackToName(): void
    {
        $model = new SiteVariableModel();
        $model->insert(['name' => 'z_setting', 'value' => '1']);
        $model->insert(['name' => 'a_setting', 'value' => '2']);

        $rows = $model->searchAdmin([], 'value; DROP TABLE site_variable;', 'asc');

        // Falls back to default sort (name asc) rather than erroring or
        // injecting the malicious column into the query.
        $this->assertSame('a_setting', $rows[0]->name);
    }

    public function testCountAdminMatchesUnpaginatedRowCountForSameFilters(): void
    {
        $model = new SiteVariableModel();
        for ($i = 0; $i < 5; $i++) {
            $model->insert(['name' => "setting_{$i}", 'value' => "{$i}"]);
        }

        $filters = ['keyword' => 'setting'];
        $page    = $model->searchAdmin($filters, 'id', 'asc', 2, 0);

        $this->assertCount(2, $page);
        $this->assertSame(5, $model->countAdmin($filters));
    }

    public function testSearchAdminExcludesSoftDeletedRows(): void
    {
        $model = new SiteVariableModel();
        $id    = $model->insert(['name' => 'deleted_setting', 'value' => '1']);
        $model->delete($id);

        $this->assertSame([], $model->searchAdmin(['keyword' => 'deleted_setting']));
        $this->assertSame(0, $model->countAdmin(['keyword' => 'deleted_setting']));
    }
}
