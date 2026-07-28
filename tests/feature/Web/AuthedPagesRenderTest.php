<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\Web\WebAuthFixture;

/**
 * Every authenticated page: does it render, and is its inline JavaScript valid?
 *
 * Two failure modes that nothing else catches, over one page list:
 *
 * 1. **A 500 from a view-variable mismatch.** Gating tests redirect before the
 *    view renders, so they never execute the template — a controller that stops
 *    passing a variable the view reads sails through them.
 *
 * 2. **Syntactically broken inline JS.** A 200 proves nothing here: when an
 *    Alpine component's `<script>` fails to parse, the component silently loses
 *    all state and *every* handler on it dies. That is what happened on
 *    `/requests`, where `esc(json_encode($date), 'js')` emitted
 *    `start_date: \x222026\x2D07\x2D25\x22` — correct escaping, invalid
 *    JavaScript in a bare expression position — and the modal could not be
 *    closed by the X, Cancel, the backdrop or Escape.
 *
 * Data going into a `<script>` body must therefore use
 * `json_encode($v, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)`.
 * The `'js'` escaper context is only correct *inside* a quoted JS string.
 *
 * @internal
 */
final class AuthedPagesRenderTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use WebAuthFixture;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;

    /**
     * Every page an admin-zone user can open. Add new ones here — the cost of a
     * row is one request, and the cost of forgetting is a 500 in production.
     *
     * @return list<array{0: string}>
     */
    public static function pageProvider(): array
    {
        return [
            ['/dashboard'],
            ['/admin/dashboard'],
            ['/attendance'],
            ['/attendance/report'],
            ['/requests'],
            ['/admin/attendance'],
            ['/admin/attendance/today'],
            ['/admin/attendance/report'],
            ['/admin/attendance/schedules'],
            ['/admin/attendance/holidays'],
            ['/admin/requests'],
            ['/admin/users'],
            ['/admin/groups'],
            ['/admin/settings'],
            ['/admin/access-logs'],
            ['/admin/scheduled-tasks'],
            ['/account'],
            ['/account/security'],
            ['/account/api-keys'],
            ['/notifications'],
            ['/docs'],
        ];
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        session()->destroy();
    }

    /**
     * @dataProvider pageProvider
     */
    public function testPageRendersAndItsInlineScriptsParse(string $page): void
    {
        $this->loginAs($this->seedWebUser(['email' => 'render@example.com', 'zone' => 'admin']));

        $result = $this->withSession()->get($page);

        // 1. It renders at all.
        $result->assertStatus(200);

        // Raw body, not `$result->getBody()`: that forwards to DOMParser and
        // returns libxml's re-serialization, so this would check a rewritten page
        // instead of the one the browser receives.
        $html = (string) $result->response()->getBody();

        // 2. Its inline JavaScript parses. Skipped (not failed) where node is
        //    unavailable, so the render assertion above still runs everywhere.
        exec('node --version 2>/dev/null', $probe, $probeCode);

        if ($probeCode !== 0) {
            $this->markTestSkipped('node is not available to parse the inline scripts.');
        }

        preg_match_all('#<script>(.*?)</script>#s', $html, $matches);

        foreach (array_filter($matches[1], static fn (string $js): bool => trim($js) !== '') as $index => $js) {
            $file = tempnam(sys_get_temp_dir(), 'jsprobe') . '.js';
            file_put_contents($file, $js);

            $output = [];
            exec('node --check ' . escapeshellarg($file) . ' 2>&1', $output, $code);
            @unlink($file);

            $this->assertSame(0, $code, "{$page} inline script #{$index} is not valid JavaScript:\n" . implode("\n", array_slice($output, 0, 5)));
        }
    }
}
