<?php

use App\Models\TranslationModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * @internal
 */
final class TranslationModelTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;

    public function testFindTranslationReturnsRow(): void
    {
        $model = new TranslationModel();
        $id    = $model->insert(['lang_code' => 'en', 'slug' => 'welcome', 'content' => 'Welcome']);

        $translation = $model->findTranslation($id);

        $this->assertNotNull($translation);
        $this->assertSame('welcome', $translation->slug);
    }

    public function testFindTranslationReturnsNullForUnknownId(): void
    {
        $model = new TranslationModel();

        $this->assertNull($model->findTranslation(999999));
    }

    public function testExistsForLangSlugDetectsDuplicateAndIgnoresOtherLanguages(): void
    {
        $model = new TranslationModel();
        $model->insert(['lang_code' => 'en', 'location' => 'probe', 'slug' => 'welcome', 'content' => 'Welcome']);

        // Scoped to the location as well now, matching the table's unique key.
        $this->assertTrue($model->existsForLangSlug('en', 'welcome', null, 'probe'));
        $this->assertFalse($model->existsForLangSlug('ja', 'welcome', null, 'probe'));
        $this->assertFalse($model->existsForLangSlug('en', 'goodbye', null, 'probe'));
        $this->assertFalse($model->existsForLangSlug('en', 'welcome', null, 'other'), 'Another group may reuse the slug.');
    }

    public function testExistsForLangSlugExcludesGivenId(): void
    {
        $model = new TranslationModel();
        $id    = $model->insert(['lang_code' => 'en', 'location' => 'probe', 'slug' => 'welcome', 'content' => 'Welcome']);

        $this->assertFalse($model->existsForLangSlug('en', 'welcome', $id, 'probe'));

        $model->insert(['lang_code' => 'en', 'location' => 'probe', 'slug' => 'goodbye', 'content' => 'Bye']);
        $this->assertTrue($model->existsForLangSlug('en', 'goodbye', $id, 'probe'));
    }

    public function testSearchAdminFiltersByKeywordAcrossSlugAndContent(): void
    {
        $model = new TranslationModel();
        $model->insert(['lang_code' => 'en', 'slug' => 'welcome', 'content' => 'Welcome aboard']);
        $model->insert(['lang_code' => 'en', 'slug' => 'goodbye', 'content' => 'See you later']);

        $bySlug = $model->searchAdmin(['keyword' => 'welcome']);
        $this->assertCount(1, $bySlug);
        $this->assertSame('welcome', $bySlug[0]->slug);

        $byContent = $model->searchAdmin(['keyword' => 'later']);
        $this->assertCount(1, $byContent);
        $this->assertSame('goodbye', $byContent[0]->slug);
    }

    public function testSearchAdminFiltersByLangCode(): void
    {
        $model = new TranslationModel();
        $model->insert(['lang_code' => 'en', 'slug' => 'welcome', 'content' => 'Welcome']);
        $model->insert(['lang_code' => 'ja', 'slug' => 'welcome', 'content' => 'ようこそ']);

        $rows = $model->searchAdmin(['lang_code' => 'ja']);

        $this->assertCount(1, $rows);
        $this->assertSame('ja', $rows[0]->lang_code);
    }

    public function testSearchAdminRejectsArbitrarySortColumnAndFallsBackToLangCode(): void
    {
        $model = new TranslationModel();
        $model->insert(['lang_code' => 'zz', 'slug' => 'a', 'content' => '1']);
        $model->insert(['lang_code' => 'aa', 'slug' => 'b', 'content' => '2']);

        $rows = $model->searchAdmin([], 'content; DROP TABLE translation;', 'asc');

        // Falls back to default sort (lang_code asc) rather than erroring or
        // injecting the malicious column into the query.
        $this->assertSame('aa', $rows[0]->lang_code);
    }

    public function testCountAdminMatchesUnpaginatedRowCountForSameFilters(): void
    {
        $model = new TranslationModel();
        for ($i = 0; $i < 5; $i++) {
            // 'ja', not 'en': the app now ships seeded en/vi nav strings, so an
            // exact count against 'en' would measure the seed as well.
            $model->insert(['lang_code' => 'ja', 'slug' => "key_{$i}", 'content' => "value {$i}"]);
        }

        $filters = ['lang_code' => 'ja'];
        $page    = $model->searchAdmin($filters, 'id', 'asc', 2, 0);

        $this->assertCount(2, $page);
        $this->assertSame(5, $model->countAdmin($filters));
    }

    public function testDistinctLangCodesReturnsUniqueSortedCodes(): void
    {
        $model = new TranslationModel();
        $model->insert(['lang_code' => 'ja', 'slug' => 'welcome', 'content' => 'ようこそ']);
        $model->insert(['lang_code' => 'ja', 'slug' => 'goodbye', 'content' => 'さようなら']);
        $model->insert(['lang_code' => 'ko', 'slug' => 'welcome', 'content' => '환영']);

        // en and vi come from the seeded nav/ui strings.
        $this->assertSame(['en', 'ja', 'ko', 'vi'], $model->distinctLangCodes());
    }

    public function testAllByLangReturnsOnlyThatLanguagesRowsOrderedBySlug(): void
    {
        $model = new TranslationModel();
        $model->insert(['lang_code' => 'ja', 'slug' => 'welcome', 'content' => 'Welcome']);
        $model->insert(['lang_code' => 'ja', 'slug' => 'goodbye', 'content' => 'Bye']);
        $model->insert(['lang_code' => 'ko', 'slug' => 'welcome', 'content' => '환영']);

        $rows = $model->allByLang('ja');

        $this->assertCount(2, $rows);
        $this->assertSame('goodbye', $rows[0]->slug);
        $this->assertSame('welcome', $rows[1]->slug);
    }

    public function testExportForLangReturnsSlugContentMap(): void
    {
        $model = new TranslationModel();
        $model->insert(['lang_code' => 'ja', 'slug' => 'welcome', 'content' => 'Welcome']);
        $model->insert(['lang_code' => 'ja', 'slug' => 'goodbye', 'content' => 'Bye']);
        $model->insert(['lang_code' => 'ko', 'slug' => 'welcome', 'content' => '환영']);

        $this->assertSame(
            ['goodbye' => 'Bye', 'welcome' => 'Welcome'],
            $model->exportForLang('ja'),
        );
    }

    public function testExportForLangReturnsEmptyArrayForNullOrEmptyLang(): void
    {
        $model = new TranslationModel();
        $model->insert(['lang_code' => 'en', 'slug' => 'welcome', 'content' => 'Welcome']);

        $this->assertSame([], $model->exportForLang(null));
        $this->assertSame([], $model->exportForLang(''));
    }

    public function testDeleteHardRemovesTheRow(): void
    {
        $model = new TranslationModel();
        $id    = $model->insert(['lang_code' => 'en', 'slug' => 'welcome', 'content' => 'Welcome']);

        $model->delete($id);

        $this->assertNull($model->find($id));
        $this->assertSame(0, $model->builder()->where('id', $id)->countAllResults());
    }
}
