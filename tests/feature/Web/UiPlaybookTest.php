<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\Web\WebAuthFixture;

/**
 * The interface upgrades from the UI/UX playbook that can actually be asserted
 * server-side: cache-busted assets, the global select rule, the readability
 * overrides, the dark-mode canvas, the time-ago hydrator and the pagination
 * footer.
 *
 * Everything here reads `->response()->getBody()`. `TestResponse` has no
 * `getBody()`, so the plain call is forwarded to `DOMParser` and returns libxml's
 * re-serialization, which drops every `@click` and lowercases attribute names.
 *
 * @internal
 */
final class UiPlaybookTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use WebAuthFixture;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;

    private function css(): string
    {
        return (string) file_get_contents(FCPATH . 'css/app.css');
    }

    private function asAdmin(string $email): void
    {
        $this->loginAs($this->seedWebUser(['email' => $email, 'zone' => 'admin']));
    }

    // ------------------------------------------------------- cache busting

    public function testEveryPageCacheBustsTheStylesheet(): void
    {
        $this->asAdmin('bust@example.com');

        $pages = [
            ['/dashboard', true],
            ['/admin/users', true],
            ['/login', false],
        ];

        foreach ($pages as [$page, $authed]) {
            if (! $authed) {
                session()->destroy();
            }

            $result = $authed
                ? $this->withSession()->get($page)
                : $this->withSession(['basicAuthAuthenticated' => 1])->get($page);

            $body = (string) $result->response()->getBody();

            $this->assertMatchesRegularExpression(
                '#/css/app\.css\?v=[^"]+#',
                $body,
                $page . ' must append a cache-busting version to the stylesheet.',
            );
        }
    }

    /**
     * A configured `asset.version` is a *stable* token — that is the whole point,
     * since a random one in production would re-download the CSS on every view.
     */
    public function testAConfiguredAssetVersionIsStableAcrossRenders(): void
    {
        helper('app');
        putenv('asset.version=deploy-42');
        $_ENV['asset.version']    = 'deploy-42';
        $_SERVER['asset.version'] = 'deploy-42';

        try {
            $this->assertSame('deploy-42', asset_version());
            $this->assertSame(asset_version(), asset_version());
        } finally {
            putenv('asset.version');
            unset($_ENV['asset.version'], $_SERVER['asset.version']);
        }
    }

    public function testWithNoConfiguredVersionEachRenderDiffers(): void
    {
        helper('app');

        // env() looks in $_ENV, $_SERVER *and* getenv(); DotEnv seeds all of them
        // from .env, so clearing one is not enough.
        putenv('asset.version');
        unset($_ENV['asset.version'], $_SERVER['asset.version']);

        $seen = [];

        for ($i = 0; $i < 20; $i++) {
            $seen[asset_version()] = true;
        }

        $this->assertGreaterThan(1, count($seen), 'Without a configured token the version must vary so edits show up.');
    }

    // ---------------------------------------------------------- global CSS

    public function testTheCompiledCssStylesEverySelectNotJustFormSelect(): void
    {
        $css = $this->css();

        $this->assertStringContainsString('select:not([multiple])', $css);
        $this->assertStringContainsString('.dark select:not([multiple])', $css);

        // A chevron for each mode, identified by its stroke rather than by
        // counting data URIs: Tailwind also emits an `.!dark` important-variant of
        // the dark rule, so the count is not ours to predict.
        $this->assertStringContainsString("stroke='%23787774'", $css, 'light-mode chevron');
        $this->assertStringContainsString("stroke='%239ca3af'", $css, 'dark-mode chevron');

        // padding-right is the one property that must beat the pr-*/px-* utilities.
        $this->assertMatchesRegularExpression('/select:not\(\[multiple\]\)\{[^}]*padding-right:2\.5rem!important/', $css);
    }

    /**
     * The readability overrides have to come *after* Tailwind's own utilities or
     * source order lets the originals win.
     */
    public function testTheReadabilityOverridesWinBySourceOrder(): void
    {
        $css = $this->css();

        preg_match_all('/\.text-sm\{font-size:([^;]+);/', $css, $matches);

        $this->assertGreaterThanOrEqual(2, count($matches[1]), 'Expected Tailwind\'s .text-sm and our override.');
        $this->assertSame('.875rem', $matches[1][0], 'Tailwind\'s own rule should come first.');
        $this->assertSame('.9375rem', $matches[1][count($matches[1]) - 1], 'Our override must be last.');

        // The muted token: #787774 measures 4.48:1 on white, which fails AA.
        $this->assertMatchesRegularExpression('/\.text-\\\\\[\\\\#787774\\\\\]\{color:#5c5955\}/', $css);
    }

    public function testTheXCloakGuardLivesInTheStylesheetNotInEveryTemplate(): void
    {
        $this->assertMatchesRegularExpression('/\[x-cloak\]\{display:none/', $this->css());

        $this->asAdmin('cloak@example.com');
        $body = (string) $this->withSession()->get('/dashboard')->response()->getBody();

        $this->assertStringNotContainsString('<style>[x-cloak]', $body, 'The inline copies should be gone.');
    }

    // ------------------------------------------------------- dark mode canvas

    /**
     * @dataProvider canvasPageProvider
     */
    public function testThePrePaintScriptColoursTheHtmlCanvas(string $page, bool $authed): void
    {
        if ($authed) {
            $this->asAdmin('canvas@example.com');
            $body = (string) $this->withSession()->get($page)->response()->getBody();
        } else {
            $body = (string) $this->withSession(['basicAuthAuthenticated' => 1])->get($page)->response()->getBody();
        }

        // Without this the dark background lives only on <body>, so <html> flashes
        // white on every full-page navigation.
        $this->assertStringContainsString('documentElement.style.backgroundColor', $body, $page);
        $this->assertStringContainsString('#111827', $body, $page);
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function canvasPageProvider(): array
    {
        return [
            'layout'    => ['/dashboard', true],
            'login'     => ['/login', false],
            'gate'      => ['/basic-auth', false],
            'verify 2fa' => ['/login', false],
        ];
    }

    /**
     * The toggle has to move the canvas too, or the *next* navigation starts from
     * the old colour and flashes.
     */
    public function testTheThemeToggleAlsoMovesTheCanvas(): void
    {
        $this->asAdmin('toggle@example.com');
        $body = (string) $this->withSession()->get('/dashboard')->response()->getBody();

        $this->assertMatchesRegularExpression(
            '/classList\.toggle\(.dark., dark\);\s*document\.documentElement\.style\.backgroundColor/',
            $body,
        );
    }

    // ------------------------------------------------------------- time ago

    public function testTheTimeAgoHydratorIsShippedAndDegradesGracefully(): void
    {
        $this->asAdmin('ago@example.com');

        $body = (string) $this->withSession()->get('/admin/users')->response()->getBody();

        $this->assertStringContainsString('window.timeAgo', $body);
        // With the quote: the bare string also occurs in the hydrator's
        // querySelectorAll('[data-timeago]') on every single page.
        $this->assertStringContainsString('data-timeago="', $body);

        // Progressive enhancement: the raw timestamp is still the element's text,
        // so the page reads correctly with JavaScript off.
        $admin = model(App\Models\UserModel::class)->findActiveByEmail('ago@example.com');
        $this->assertNotNull($admin->login_at, 'loginAs() should have stamped login_at.');

        // `esc($v, 'attr')` encodes the space as `&#x20;`, which is why this
        // decodes before comparing. Harmless in the browser — getAttribute()
        // returns the decoded value — but it means a raw string match would fail.
        $this->assertSame(
            1,
            preg_match('/data-timeago="([^"]+)">([^<]+)</', $body, $cell),
            'The login_at cell should carry the timestamp for hydration.',
        );

        $this->assertSame($admin->login_at, html_entity_decode($cell[1], ENT_QUOTES, 'UTF-8'), 'attribute value');
        $this->assertSame($admin->login_at, trim($cell[2]), 'visible text, for JavaScript-off readers');
    }

    /**
     * The attendance screens already render Vietnam-local times. Hydrating those
     * would treat a local time as UTC and be seven hours out, so they must not
     * carry the attribute.
     */
    public function testTheAttendancePagesAreNotHydrated(): void
    {
        $this->asAdmin('noago@example.com');

        foreach (['/attendance', '/admin/attendance/today', '/admin/access-logs'] as $page) {
            $body = (string) $this->withSession()->get($page)->response()->getBody();
            $this->assertStringNotContainsString('data-timeago="', $body, $page . ' shows local times and must not be hydrated.');
        }
    }

    // ----------------------------------------------------- pagination footer

    /**
     * @dataProvider listPageProvider
     */
    public function testRowsPerPageLivesInThePaginationFooter(string $page): void
    {
        $this->asAdmin('pager@example.com');

        $body = (string) $this->withSession()->get($page)->response()->getBody();

        $this->assertStringContainsString('Rows per page', $body, $page);
        // Self-navigating, so it needs no form around it.
        $this->assertStringContainsString("searchParams.set('limit', this.value)", $body, $page);
        $this->assertStringContainsString("searchParams.set('page', '1')", $body, $page);
    }

    /**
     * Pressing Search must not silently reset the page size — the filter form no
     * longer holds the select, so it has to carry the value as a hidden field.
     */
    public function testTheFilterFormPreservesTheChosenPageSize(): void
    {
        $this->asAdmin('keeplimit@example.com');

        $body = (string) $this->withSession()->get('/admin/users?limit=100')->response()->getBody();

        $this->assertStringContainsString('<input type="hidden" name="limit" value="100">', $body);
    }

    /**
     * A hand-typed limit still has to be visible in the select, or it silently
     * displays the wrong number.
     */
    public function testAnUnlistedLimitStillAppearsAsSelected(): void
    {
        $this->asAdmin('oddlimit@example.com');

        $body = (string) $this->withSession()->get('/admin/users?limit=40')->response()->getBody();

        $this->assertStringContainsString('<option value="40" selected>40</option>', $body);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function listPageProvider(): array
    {
        return [
            'users'           => ['/admin/users'],
            'groups'          => ['/admin/groups'],
            'settings'        => ['/admin/settings'],
            'emails'          => ['/admin/emails'],
            'scheduled tasks' => ['/admin/scheduled-tasks'],
            'migrations'      => ['/admin/system/migrations'],
        ];
    }

    // ----------------------------------------------------------------- mobile

    /**
     * Regression: the content column must be allowed to shrink below its content.
     *
     * A flex item defaults to `min-width: auto`, so it refuses to go narrower than
     * its widest child. This column holds the tables, so without `min-w-0` it grew
     * to the widest table and dragged the header and the whole page with it —
     * measured at +137px on /attendance and +706px on /admin/attendance/report at
     * a 375px viewport, i.e. every table page scrolled sideways on a phone while
     * the `overflow-x-auto` wrappers sat there unused.
     *
     * One class, every page. Asserted here because it looks like decoration and is
     * exactly the kind of thing a later tidy-up deletes.
     */
    public function testTheContentColumnCanShrinkBelowItsTables(): void
    {
        $this->asAdmin('mobile@example.com');

        $body = (string) $this->withSession()->get('/admin/users')->response()->getBody();

        $this->assertMatchesRegularExpression(
            '/<div class="flex min-h-screen min-w-0 flex-1 flex-col/',
            $body,
            'The main content column needs min-w-0, or every table page overflows horizontally.',
        );
    }

    /**
     * The employee-facing tables drop their least essential columns below `sm` so
     * they fit a phone without sideways scrolling. Measured: /attendance,
     * /attendance/report and /requests all fit at 360px.
     *
     * @dataProvider narrowTableProvider
     */
    public function testEmployeeTablesHideLowPriorityColumnsOnSmallScreens(string $page, int $expected): void
    {
        $this->asAdmin('narrow@example.com');

        $body = (string) $this->withSession()->get($page)->response()->getBody();

        $this->assertSame(
            $expected,
            preg_match_all('/<th[^>]*\bhidden\b[^>]*sm:table-cell/', $body),
            $page . ' should hide exactly ' . $expected . ' header cells below sm.',
        );

        // Every hidden header needs its body cells hidden too, or the columns
        // shift out of alignment at narrow widths.
        $this->assertGreaterThanOrEqual(
            $expected,
            preg_match_all('/<t[dh][^>]*\bhidden\b[^>]*sm:table-cell/', $body),
        );
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function narrowTableProvider(): array
    {
        return [
            'my attendance' => ['/attendance', 2],        // Worked, Note
            'my report'     => ['/attendance/report', 2], // Day, Worked
            'my requests'   => ['/requests', 2],          // Days, Reason
        ];
    }
}
