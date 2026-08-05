<?php

use App\Filters\LocaleFilter;
use App\Models\SiteVariableModel;
use App\Models\TranslationModel;
use App\Models\UserModel;
use CodeIgniter\Config\Services;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\Web\WebAuthFixture;

/**
 * The multi-language feature, end to end.
 *
 * Before this, `/admin/translations` was CRUD over a table nothing read: the only
 * `lang()` calls in the whole view tree were in CodeIgniter's stock error pages,
 * which use the framework's own file-based strings, and no code set a locale. So
 * these tests are the difference between "there is a translations screen" and
 * "translating something changes the page".
 *
 * @internal
 */
final class LocaleTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use WebAuthFixture;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;

    /**
     * `language` is a shared service and caches its strings per instance, so a
     * test that switched to Vietnamese would otherwise leak that locale into every
     * test after it. The `request` goes with it because that is where the locale
     * is read from.
     */
    protected function tearDown(): void
    {
        Services::resetSingle('language');
        Services::resetSingle('request');
        parent::tearDown();
        session()->destroy();
    }

    private function body(string $page): string
    {
        return (string) $this->withSession()->get($page)->response()->getBody();
    }

    // --------------------------------------------------------- the mechanism

    /**
     * The heart of it: a row in `translation` reaches `lang()`.
     */
    public function testADatabaseRowAnswersALangCall(): void
    {
        service('request')->setLocale('vi');
        Services::resetSingle('language');

        // Seeded by 2026-08-05-020000_SeedNavTranslations.
        $this->assertSame('Trang chủ', lang('nav.dashboard'));
        $this->assertSame('Chấm công của tôi', lang('nav.my_attendance'));
    }

    public function testEditingATranslationChangesWhatLangReturns(): void
    {
        model(TranslationModel::class)
            ->where(['lang_code' => 'vi', 'location' => 'nav', 'slug' => 'dashboard'])
            ->set('content', 'Bảng điều khiển')
            ->update();

        service('request')->setLocale('vi');
        Services::resetSingle('language');

        $this->assertSame('Bảng điều khiển', lang('nav.dashboard'));
    }

    /**
     * A key with no row anywhere must not blow up or render blank — CI4 returns
     * the key, which is at least diagnosable.
     */
    public function testAMissingKeyDegradesToTheKey(): void
    {
        service('request')->setLocale('vi');
        Services::resetSingle('language');

        $this->assertSame('nav.no_such_key', lang('nav.no_such_key'));
    }

    /**
     * The database layers *over* file strings rather than replacing them, so the
     * framework's own messages keep working in a locale that has no files.
     */
    public function testFrameworkStringsStillResolve(): void
    {
        service('request')->setLocale('vi');
        Services::resetSingle('language');

        $this->assertNotSame('Validation.required', lang('Validation.required'));
    }

    // ------------------------------------------------------------ resolution

    public function testThePageRendersInTheSessionLocale(): void
    {
        $this->loginAs($this->seedWebUser(['email' => 'vi@example.com', 'zone' => 'admin']));

        $english = $this->body('/admin/dashboard');
        $this->assertStringContainsString('Admin console', $english);

        session()->set(LocaleFilter::SESSION_KEY, 'vi');

        $vietnamese = $this->body('/admin/dashboard');
        $this->assertStringContainsString('Bảng quản trị', $vietnamese);
        $this->assertStringContainsString('Chấm công của tôi', $vietnamese);
    }

    /**
     * `users.language` was written and never read until now — it is what makes the
     * choice follow somebody to another browser.
     */
    public function testASavedUserLanguageAppliesWithoutASessionChoice(): void
    {
        $this->loginAs($this->seedWebUser(['email' => 'saved@example.com', 'zone' => 'admin', 'language' => 'vi']));

        $this->assertStringContainsString('Bảng quản trị', $this->body('/admin/dashboard'));
    }

    /**
     * An unsupported locale must be ignored, not trusted: CI4 interpolates the
     * locale into a filesystem path when loading language files.
     *
     * @dataProvider hostileLocaleProvider
     */
    public function testAnUnsupportedLocaleIsIgnored(string $locale): void
    {
        $this->loginAs($this->seedWebUser(['email' => 'hostile@example.com', 'zone' => 'admin']));
        session()->set(LocaleFilter::SESSION_KEY, $locale);

        // Falls back to the default rather than honouring it.
        $this->assertStringContainsString('Admin console', $this->body('/admin/dashboard'));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function hostileLocaleProvider(): array
    {
        return [
            'unknown'   => ['de'],
            'traversal' => ['../../../etc'],
            'empty'     => [''],
        ];
    }

    // -------------------------------------------------------------- switcher

    public function testTheSwitcherIsInTheHeaderWithEverySupportedLanguage(): void
    {
        $this->loginAs($this->seedWebUser(['email' => 'switcher@example.com', 'zone' => 'admin']));

        $body = $this->body('/admin/dashboard');

        // Labelled in their own language: somebody looking for Vietnamese scans
        // for "Tiếng Việt", not for "Vietnamese".
        $this->assertStringContainsString('English', $body);
        $this->assertStringContainsString('Tiếng Việt', $body);
        $this->assertStringContainsString("choose('vi')", $body);
    }

    public function testSwitchingPersistsToTheSessionAndTheUserRow(): void
    {
        $user = $this->seedWebUser(['email' => 'persist@example.com', 'zone' => 'admin']);
        $this->loginAs($user);

        $result = $this->withSession()->post('/locale/switch', $this->withCsrf(['locale' => 'vi']));

        $result->assertStatus(200);
        $this->assertSame('vi', json_decode($result->getJSON(), true)['locale']);
        $this->assertSame('vi', session(LocaleFilter::SESSION_KEY));
        $this->assertSame('vi', model(UserModel::class)->find($user->id)->language, 'The choice should survive a new session.');
    }

    /**
     * Regression: this route deliberately has no `authFilter`, and `AuthFilter` is
     * what loads the `auth` helper — so the controller has to load it itself. The
     * first version did not, and the switch 500'd on `current_user_id()` *after*
     * writing the session, which made it look like a JavaScript bug.
     */
    public function testSwitchingWorksForAGuestOnTheLoginPage(): void
    {
        $result = $this->withSession(['basicAuthAuthenticated' => 1])
            ->post('/locale/switch', $this->withCsrf(['locale' => 'vi']));

        $result->assertStatus(200);
        $this->assertSame('success', json_decode($result->getJSON(), true)['status']);
        $this->assertSame('vi', session(LocaleFilter::SESSION_KEY));
    }

    /**
     * @dataProvider hostileLocaleProvider
     */
    public function testSwitchingToAnUnsupportedLocaleIsRefused(string $locale): void
    {
        $this->loginAs($this->seedWebUser(['email' => 'refuse-' . md5($locale) . '@example.com']));

        $result = $this->withSession()->post('/locale/switch', $this->withCsrf(['locale' => $locale]));

        $this->assertNotSame(200, $result->response()->getStatusCode());
        $this->assertNull(session(LocaleFilter::SESSION_KEY));
    }

    /**
     * An admin acting as somebody else must not rewrite that person's saved
     * language — the session choice is enough for the impersonated view.
     */
    public function testActingAsSomebodyElseDoesNotRewriteTheirLanguage(): void
    {
        $admin  = $this->seedWebUser(['email' => 'actor@example.com', 'zone' => 'admin']);
        $target = $this->seedWebUser(['email' => 'acted-on@example.com', 'language' => 'en']);
        $this->loginAs($admin);

        $this->withSession()->post('/admin/users/act-as/' . $target->id, $this->withCsrf([]));
        $this->withSession()->post('/locale/switch', $this->withCsrf(['locale' => 'vi']));

        $this->assertSame('en', model(UserModel::class)->find($target->id)->language);
        $this->assertSame('vi', session(LocaleFilter::SESSION_KEY), 'The view still switches for this session.');
    }

    // ------------------------------------------------------- the admin screen

    /**
     * The admin screen must actually be able to add a string that then resolves —
     * otherwise it is still a form over a table nobody reads.
     */
    public function testAStringAddedThroughTheAdminScreenResolves(): void
    {
        $this->loginAs($this->seedWebUser(['email' => 'translator@example.com', 'zone' => 'admin']));

        $this->withSession()->post('/admin/translations/create', $this->withCsrf([
            'lang_code' => 'vi',
            'location'  => 'probe',
            'slug'      => 'greeting',
            'content'   => 'Xin chào',
        ]))->assertStatus(200);

        service('request')->setLocale('vi');
        Services::resetSingle('language');

        $this->assertSame('Xin chào', lang('probe.greeting'));
    }

    /**
     * Settings and translations are separate systems; a translation must not need
     * a site variable to work.
     */
    public function testTranslationsDoNotDependOnSiteVariables(): void
    {
        model(SiteVariableModel::class)->where('id >', 0)->delete();

        service('request')->setLocale('vi');
        Services::resetSingle('language');

        $this->assertSame('Trang chủ', lang('nav.dashboard'));
    }
}
