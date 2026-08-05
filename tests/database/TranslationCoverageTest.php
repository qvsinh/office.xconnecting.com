<?php

use App\Models\TranslationModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\App;

/**
 * Every `lang()` key used in a view must have a row in every supported language.
 *
 * This is the gate that makes internationalising the views safe. A missing or
 * mistyped key does not error in CodeIgniter — `lang('foo.bar')` just renders the
 * string "foo.bar", which looks like a typo in the copy and is easy to miss on a
 * page nobody opened during review. Rather than trusting a read-through of forty
 * templates, this extracts the keys from the templates themselves and checks them
 * against the database.
 *
 * Framework groups are excluded: CodeIgniter ships its own `Validation.*`,
 * `HTTP.*` and similar strings as files, and they are not ours to seed.
 *
 * @internal
 */
final class TranslationCoverageTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;

    /**
     * Groups that belong to CodeIgniter (or a package), shipped as language files.
     * Capitalised by convention, which is how they are told apart from ours.
     */
    private const FRAMEWORK_GROUPS = [
        'Validation', 'HTTP', 'Database', 'Files', 'Format', 'Images', 'Cache',
        'Cast', 'CLI', 'Core', 'Encryption', 'Email', 'Filters', 'Log',
        'Migrations', 'Number', 'Pager', 'Router', 'Security', 'Session',
        'Test', 'Time', 'Validation', 'View', 'Cookie', 'Publisher',
    ];

    /**
     * Every `lang('group.key')` literal in the view tree.
     *
     * @return array<string, list<string>> key => files that use it
     */
    private function keysUsedInViews(): array
    {
        $found = [];

        $directory = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(APPPATH . 'Views', FilesystemIterator::SKIP_DOTS),
        );

        foreach ($directory as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace(APPPATH . 'Views/', '', $file->getPathname());

            // The framework's own error pages use framework strings.
            if (str_starts_with($relative, 'errors/')) {
                continue;
            }

            preg_match_all("/\blang\(\s*'([A-Za-z0-9_]+)\.([A-Za-z0-9_.]+)'/", (string) file_get_contents($file->getPathname()), $matches, PREG_SET_ORDER);

            foreach ($matches as $match) {
                [, $group, $key] = $match;

                if (in_array($group, self::FRAMEWORK_GROUPS, true)) {
                    continue;
                }

                $found[$group . '.' . $key][] = $relative;
            }
        }

        return $found;
    }

    public function testEveryKeyUsedInAViewExistsInEverySupportedLanguage(): void
    {
        $used = $this->keysUsedInViews();

        $this->assertNotSame([], $used, 'No lang() keys found at all — has the extraction regex drifted?');

        $model  = model(TranslationModel::class);
        $rows   = $model->select('lang_code, location, slug')->findAll();
        $have   = [];

        foreach ($rows as $row) {
            $have[$row->location . '.' . $row->slug][$row->lang_code] = true;
        }

        $missing = [];

        foreach (config(App::class)->supportedLocales as $locale) {
            foreach ($used as $key => $files) {
                if (! isset($have[$key][$locale])) {
                    $missing[] = sprintf('%s missing for "%s" (used in %s)', $key, $locale, implode(', ', array_unique($files)));
                }
            }
        }

        $this->assertSame([], $missing, "Untranslated keys:\n  " . implode("\n  ", $missing));
    }

    /**
     * The reverse direction, as a tidiness check rather than a correctness one:
     * rows nothing references are dead weight an administrator has to scroll past.
     *
     * Not a failure — a project may legitimately seed strings for code that has not
     * landed yet — so this reports rather than asserts.
     */
    public function testReportsSeededKeysNoViewUses(): void
    {
        $used = $this->keysUsedInViews();
        $rows = model(TranslationModel::class)->select('location, slug')->findAll();

        $orphans = [];

        foreach ($rows as $row) {
            $key = $row->location . '.' . $row->slug;

            if (! isset($used[$key])) {
                $orphans[$key] = true;
            }
        }

        if ($orphans !== []) {
            fwrite(STDERR, "\n  note: " . count($orphans) . " seeded key(s) no view references: " . implode(', ', array_slice(array_keys($orphans), 0, 12)) . "\n");
        }

        $this->assertTrue(true);
    }
}
