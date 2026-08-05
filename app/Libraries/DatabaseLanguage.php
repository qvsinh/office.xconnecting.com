<?php

namespace App\Libraries;

use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\Language\Language;
use Throwable;

/**
 * Makes `lang('group.key')` resolve from the `translation` table, so
 * `/admin/translations` actually changes what the app says.
 *
 * Before this, that admin screen was a CRUD form over a table nothing read: the
 * only `lang()` calls in the whole view tree were in CodeIgniter's stock error
 * pages, which use the framework's own file-based strings.
 *
 * ## How it layers
 *
 * Database rows are merged *on top of* the file-based strings in
 * `app/Language/<locale>/`, not instead of them. So the framework's own messages
 * keep working, a project can ship defaults as files, and an administrator can
 * override any single string without touching the filesystem. A locale that exists
 * only in the database needs no files at all — `requireFile()` returns an empty
 * array for a path it cannot find.
 *
 * The table's `location` column is CI4's "file" part and `slug` is the key, so a
 * row `('vi', 'nav', 'dashboard', 'Trang chủ')` answers `lang('nav.dashboard')`.
 *
 * ## Failure is silent by design
 *
 * A missing table or an unreachable database falls back to the file strings rather
 * than throwing. Translation is presentation: a broken lookup should leave English
 * on the screen, never a 500 — and this runs on every page, including the ones an
 * admin would use to fix the database.
 */
class DatabaseLanguage extends Language
{
    /**
     * Rows already fetched, keyed "locale|file", so one page render costs at most
     * one query per language group it touches rather than one per string.
     *
     * @var array<string, array<string, string>>
     */
    private array $fetched = [];

    /**
     * True once a lookup has failed, so a missing table costs one failed query per
     * request instead of one per group.
     */
    private bool $databaseUnavailable = false;

    /**
     * Re-reads the locale from the current request before every lookup.
     *
     * `service('language')` returns a shared instance, and once it exists CI4
     * hands it back without re-entering the service factory — so the locale it was
     * *first* built with sticks for the life of the process, and
     * `Language::setLocale(null)` is a no-op that cannot correct it.
     *
     * One request per php-fpm process hides this. Anything serving several
     * requests in one process sees it plainly: `php spark serve`, a queue worker,
     * and the test suite all render every later request in the first one's
     * language. Measured, not theorised — a feature test showed
     * `request locale = vi` while the language service still said `en`.
     *
     * Cheap: a service lookup and a string compare, and `$fetched` is keyed by
     * locale so switching mid-process re-queries rather than serving stale rows.
     */
    public function getLine(string $line, array $args = [])
    {
        $request = service('request');

        if ($request instanceof IncomingRequest) {
            $locale = $request->getLocale();

            if ($locale !== '' && $locale !== $this->locale) {
                $this->setLocale($locale);
            }
        }

        return parent::getLine($line, $args);
    }

    protected function load(string $file, string $locale, bool $return = false)
    {
        $fromFiles = parent::load($file, $locale, $return);
        $fromDb    = $this->fromDatabase($file, $locale);

        if ($return) {
            // `$fromFiles` is the file array in return mode.
            return $fromDb === [] ? $fromFiles : array_replace($fromFiles, $fromDb);
        }

        if ($fromDb !== []) {
            // parent::load() has already stored the file strings under this file;
            // database rows win where both define the same key.
            $this->language[$locale][$file] = array_replace(
                $this->language[$locale][$file] ?? [],
                $fromDb,
            );
        }

        return [];
    }

    /**
     * `slug => content` for one locale and group.
     *
     * @return array<string, string>
     */
    private function fromDatabase(string $file, string $locale): array
    {
        $cacheKey = $locale . '|' . $file;

        if (array_key_exists($cacheKey, $this->fetched)) {
            return $this->fetched[$cacheKey];
        }

        if ($this->databaseUnavailable) {
            return [];
        }

        try {
            $rows = db_connect()
                ->table('translation')
                ->select('slug, content')
                ->where('lang_code', $locale)
                ->where('location', $file)
                ->get()
                ->getResultArray();

            $strings = [];

            foreach ($rows as $row) {
                $slug = (string) ($row['slug'] ?? '');

                if ($slug !== '') {
                    $strings[$slug] = (string) ($row['content'] ?? '');
                }
            }

            return $this->fetched[$cacheKey] = $strings;
        } catch (Throwable $e) {
            // Before the migration has run, or if the database is down. Fall back
            // to the file strings so the page still renders in English.
            $this->databaseUnavailable = true;

            log_message('warning', 'Falling back to file translations: {msg}', ['msg' => $e->getMessage()]);

            return [];
        }
    }
}
