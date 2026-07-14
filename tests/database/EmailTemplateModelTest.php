<?php

use App\Models\EmailTemplateModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * @internal
 */
final class EmailTemplateModelTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;

    public function testFindTemplateReturnsRow(): void
    {
        $model = new EmailTemplateModel();
        $id    = $model->insert(['name' => 'signup_verify_email', 'lang_code' => 'en', 'subject' => 'Verify your email', 'content' => 'Hello {name}']);

        $template = $model->findTemplate($id);

        $this->assertNotNull($template);
        $this->assertSame('signup_verify_email', $template->name);
    }

    public function testFindTemplateReturnsNullForUnknownId(): void
    {
        $model = new EmailTemplateModel();

        $this->assertNull($model->findTemplate(999999));
    }

    public function testExistsForNameLangDetectsDuplicateAndIgnoresOtherLanguages(): void
    {
        $model = new EmailTemplateModel();
        $model->insert(['name' => 'signup_verify_email', 'lang_code' => 'en', 'subject' => 'Verify', 'content' => 'Hi']);

        $this->assertTrue($model->existsForNameLang('signup_verify_email', 'en'));
        $this->assertFalse($model->existsForNameLang('signup_verify_email', 'ja'));
        $this->assertFalse($model->existsForNameLang('change_email', 'en'));
    }

    public function testExistsForNameLangExcludesGivenId(): void
    {
        $model = new EmailTemplateModel();
        $id    = $model->insert(['name' => 'signup_verify_email', 'lang_code' => 'en', 'subject' => 'Verify', 'content' => 'Hi']);

        $this->assertFalse($model->existsForNameLang('signup_verify_email', 'en', $id));

        $model->insert(['name' => 'change_email', 'lang_code' => 'en', 'subject' => 'Changed', 'content' => 'Hi']);
        $this->assertTrue($model->existsForNameLang('change_email', 'en', $id));
    }

    public function testSearchAdminFiltersByKeywordAcrossNameAndSubject(): void
    {
        $model = new EmailTemplateModel();
        $model->insert(['name' => 'signup_verify_email', 'lang_code' => 'en', 'subject' => 'Verify your account', 'content' => '1']);
        $model->insert(['name' => 'change_email', 'lang_code' => 'en', 'subject' => 'Your email changed', 'content' => '2']);

        $byName = $model->searchAdmin(['keyword' => 'signup']);
        $this->assertCount(1, $byName);
        $this->assertSame('signup_verify_email', $byName[0]->name);

        $bySubject = $model->searchAdmin(['keyword' => 'changed']);
        $this->assertCount(1, $bySubject);
        $this->assertSame('change_email', $bySubject[0]->name);
    }

    public function testSearchAdminFiltersByLangCode(): void
    {
        $model = new EmailTemplateModel();
        $model->insert(['name' => 'signup_verify_email', 'lang_code' => 'en', 'subject' => 'Verify', 'content' => '1']);
        $model->insert(['name' => 'signup_verify_email', 'lang_code' => 'ja', 'subject' => 'Verify JA', 'content' => '2']);

        $rows = $model->searchAdmin(['lang_code' => 'ja']);

        $this->assertCount(1, $rows);
        $this->assertSame('ja', $rows[0]->lang_code);
    }

    public function testSearchAdminRejectsArbitrarySortColumnAndFallsBackToId(): void
    {
        $model = new EmailTemplateModel();
        $idFirst  = $model->insert(['name' => 'a', 'lang_code' => 'en', 'subject' => 'a', 'content' => '1']);
        $idSecond = $model->insert(['name' => 'b', 'lang_code' => 'en', 'subject' => 'b', 'content' => '2']);

        $rows = $model->searchAdmin([], 'subject; DROP TABLE email_templates;', 'asc');

        // Falls back to default sort (id asc) rather than erroring or
        // injecting the malicious column into the query.
        $this->assertSame($idFirst, (int) $rows[0]->id);
        $this->assertSame($idSecond, (int) $rows[1]->id);
    }

    public function testCountAdminMatchesUnpaginatedRowCountForSameFilters(): void
    {
        $model = new EmailTemplateModel();
        for ($i = 0; $i < 5; $i++) {
            $model->insert(['name' => "template_{$i}", 'lang_code' => 'en', 'subject' => "Subject {$i}", 'content' => 'body']);
        }

        $filters = ['lang_code' => 'en'];
        $page    = $model->searchAdmin($filters, 'id', 'asc', 2, 0);

        $this->assertCount(2, $page);
        $this->assertSame(5, $model->countAdmin($filters));
    }

    public function testDistinctLangCodesReturnsUniqueSortedNonNullCodes(): void
    {
        $model = new EmailTemplateModel();
        $model->insert(['name' => 'a', 'lang_code' => 'en', 'subject' => 'a', 'content' => '1']);
        $model->insert(['name' => 'b', 'lang_code' => 'en', 'subject' => 'b', 'content' => '2']);
        $model->insert(['name' => 'c', 'lang_code' => 'ja', 'subject' => 'c', 'content' => '3']);
        $model->insert(['name' => 'd', 'lang_code' => null, 'subject' => 'd', 'content' => '4']);

        $this->assertSame(['en', 'ja'], $model->distinctLangCodes());
    }

    public function testSoftDeleteExcludesRowFromFindAndSearch(): void
    {
        $model = new EmailTemplateModel();
        $id    = $model->insert(['name' => 'signup_verify_email', 'lang_code' => 'en', 'subject' => 'Verify', 'content' => '1']);

        $model->delete($id);

        $this->assertNull($model->findTemplate($id));
        $this->assertCount(0, $model->searchAdmin([]));
        $this->assertSame(0, $model->countAdmin([]));

        // Row still physically present (soft delete), just filtered out.
        $this->assertSame(1, $model->builder()->where('id', $id)->countAllResults());
    }
}
