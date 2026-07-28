<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * The pages you reach *before* logging in: do they render, is their inline
 * JavaScript valid, and does the password field have a working reveal toggle?
 *
 * `AuthedPagesRenderTest` cannot cover these — it logs in first. Yet these are
 * the two screens every user meets, and they are standalone templates (no shared
 * layout), so a broken one is invisible to every other test.
 *
 * The inline-script check matters as much here as it does for authed pages: each
 * of these screens *is* one Alpine component, so a `<script>` that fails to parse
 * takes the whole form with it — no submit, no error messages, no reveal button.
 *
 * Everything here reads `->response()->getBody()`, never `->getBody()`.
 * `TestResponse` has no `getBody()`, so that call is forwarded to `DOMParser` and
 * returns libxml's re-serialization: attribute names lowercased, void elements
 * expanded, and **every `@click` silently dropped**, because `@` is not a valid
 * attribute name to libxml. Asserting on Alpine bindings needs the raw body.
 *
 * @internal
 */
final class GuestPagesRenderTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function pageProvider(): array
    {
        return [
            'basic auth gate' => ['/basic-auth', []],
            'login'           => ['/login', ['basicAuthAuthenticated' => 1]],
        ];
    }

    /**
     * @dataProvider pageProvider
     *
     * @param array<string, mixed> $session
     */
    public function testPageRendersAndItsInlineScriptsParse(string $page, array $session): void
    {
        $result = $session === [] ? $this->get($page) : $this->withSession($session)->get($page);

        $this->assertContains(
            $result->response()->getStatusCode(),
            [200, 302],
            $page . ' should render or redirect, never error.',
        );

        if ($result->response()->getStatusCode() !== 200) {
            $this->markTestSkipped($page . ' redirected in this configuration.');
        }

        exec('node --version 2>/dev/null', $probe, $probeCode);

        if ($probeCode !== 0) {
            $this->markTestSkipped('node is not available to parse the inline scripts.');
        }

        preg_match_all('#<script>(.*?)</script>#s', (string) $result->response()->getBody(), $matches);

        foreach (array_filter($matches[1], static fn (string $js): bool => trim($js) !== '') as $index => $js) {
            $file = tempnam(sys_get_temp_dir(), 'jsprobe') . '.js';
            file_put_contents($file, $js);

            $output = [];
            exec('node --check ' . escapeshellarg($file) . ' 2>&1', $output, $code);
            @unlink($file);

            $this->assertSame(0, $code, "{$page} inline script #{$index} is not valid JavaScript:\n" . implode("\n", array_slice($output, 0, 5)));
        }
    }

    /**
     * The reveal toggle: the input's type has to be *bound* rather than fixed, or
     * the button toggles a flag nothing reads, and the Alpine flag it toggles has
     * to exist in the component's state.
     */
    public function testTheLoginPasswordFieldHasAWorkingRevealToggle(): void
    {
        $html = (string) $this->withSession(['basicAuthAuthenticated' => 1])->get('/login')->response()->getBody();

        // The input reacts to the flag...
        $this->assertStringContainsString(":type=\"passwordShown ? 'text' : 'password'\"", $html);
        // ...a button flips it...
        $this->assertStringContainsString('passwordShown = ! passwordShown', $html);
        // ...and the component actually declares it, so the expression is not
        // silently undefined.
        $this->assertStringContainsString('passwordShown: false', $html);

        // A bare `type="password"` would mean the binding was lost in an edit.
        $this->assertStringNotContainsString('id="password" name="password" type="password"', $html);
    }

    /**
     * Screen-reader users get a label that says which state the button will move
     * to, and a pressed state — an unlabelled icon button is just a mystery.
     */
    public function testTheRevealToggleIsLabelledForAssistiveTech(): void
    {
        $html = (string) $this->withSession(['basicAuthAuthenticated' => 1])->get('/login')->response()->getBody();

        $this->assertStringContainsString("passwordShown ? 'Hide password' : 'Show password'", $html);
        $this->assertStringContainsString(':aria-pressed="passwordShown', $html);
        $this->assertStringContainsString('aria-controls="password"', $html);
        // It must stay keyboard-reachable: tabindex="-1" would hide it from
        // anyone not using a mouse.
        $this->assertStringNotContainsString('tabindex="-1"', $html);
    }

    /**
     * The site gate is a login screen too, and gets the same toggle.
     */
    public function testTheGatePasswordFieldHasTheSameRevealToggle(): void
    {
        $html = (string) $this->get('/basic-auth')->response()->getBody();

        $this->assertStringContainsString(":type=\"passwordShown ? 'text' : 'password'\"", $html);
        $this->assertStringContainsString('passwordShown = ! passwordShown', $html);
        $this->assertStringContainsString('passwordShown: false', $html);
    }

    /**
     * The password must never arrive pre-filled or echoed back into the markup,
     * revealed or not.
     */
    public function testTheLoginFormNeverCarriesAValue(): void
    {
        $html = (string) $this->withSession(['basicAuthAuthenticated' => 1])->get('/login')->response()->getBody();

        $this->assertDoesNotMatchRegularExpression('/id="password"[^>]*\svalue=/', $html);
        $this->assertStringContainsString("password: ''", $html);
    }
}
