<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use Config\Database;

/**
 * Widens `translation`'s unique key from `(lang_code, slug)` to
 * `(lang_code, location, slug)`.
 *
 * The table has always had a `location` column — it is the group in
 * `lang('group.key')` — but the unique key ignored it, so a slug had to be unique
 * across the whole language. That was survivable while nothing read the table.
 * Once the views started calling `lang()` it stopped being survivable: every page
 * wants a `title`, every table wants a `date` and a `status`, and under the old key
 * only one screen in the entire app could have each.
 *
 * The alternative was to mangle every slug into `attendance_title`,
 * `requests_title` and so on — which is the group name written twice, and makes the
 * `location` column decorative. Scoping the key is the smaller change and the one
 * that matches how `lang()` addresses a string.
 *
 * `TranslationModel::existsForLangSlug()` takes `location` too now, so the admin
 * screen's duplicate check agrees with the database instead of being stricter
 * than it.
 */
class ScopeTranslationUniquenessToLocation extends Migration
{
    private const TABLE = 'translation';

    /**
     * Named by the original migration's `addUniqueKey(['lang_code', 'slug'])`,
     * which MySQL names after the first column.
     */
    private const OLD_KEY = 'lang_code_slug';

    private const NEW_KEY = 'lang_code_location_slug';

    public function up(): void
    {
        $db = Database::connect($this->DBGroup);
        $db->resetDataCache();

        if (! $db->tableExists(self::TABLE)) {
            return;
        }

        // Guarded both ways so a partially-applied state (or a re-run) converges
        // rather than erroring.
        if ($this->hasKey($db, self::OLD_KEY)) {
            $this->forge->dropKey(self::TABLE, self::OLD_KEY, false);
        }

        if (! $this->hasKey($db, self::NEW_KEY)) {
            $db->query('ALTER TABLE ' . $db->protectIdentifiers(self::TABLE, true)
                . ' ADD UNIQUE ' . $db->escapeIdentifiers(self::NEW_KEY)
                . ' (' . $db->protectIdentifiers('lang_code') . ', '
                . $db->protectIdentifiers('location') . ', '
                . $db->protectIdentifiers('slug') . ')');
        }
    }

    /**
     * Narrowing back can fail on data the wide key allowed: two groups sharing a
     * slug is legal now and would violate the old key.
     *
     * So this declines rather than throws. An earlier version threw, which broke
     * every single test — `DatabaseTestTrait` with `$refresh = true` runs the full
     * rollback before each test, and by then the seeded strings include a `title`
     * in two groups. A `down()` that cannot fully reverse should leave the schema
     * usable and say why; it must not abort the chain. Nothing is deleted either
     * way, so no data is at risk.
     */
    public function down(): void
    {
        $db = Database::connect($this->DBGroup);
        $db->resetDataCache();

        if (! $db->tableExists(self::TABLE)) {
            return;
        }

        $clashes = $db->query(
            'SELECT COUNT(*) AS n FROM (SELECT lang_code, slug FROM '
            . $db->protectIdentifiers(self::TABLE, true)
            . ' GROUP BY lang_code, slug HAVING COUNT(*) > 1) AS d',
        )->getRow();

        if ((int) ($clashes->n ?? 0) > 0) {
            log_message('warning', 'Keeping UNIQUE(lang_code, location, slug): {n} slug(s) are used by more than one location, so the narrower key cannot be restored.', [
                'n' => (int) $clashes->n,
            ]);

            return;
        }

        if ($this->hasKey($db, self::NEW_KEY)) {
            $this->forge->dropKey(self::TABLE, self::NEW_KEY, false);
        }

        if (! $this->hasKey($db, self::OLD_KEY)) {
            $db->query('ALTER TABLE ' . $db->protectIdentifiers(self::TABLE, true)
                . ' ADD UNIQUE ' . $db->escapeIdentifiers(self::OLD_KEY)
                . ' (' . $db->protectIdentifiers('lang_code') . ', '
                . $db->protectIdentifiers('slug') . ')');
        }
    }

    private function hasKey(object $db, string $name): bool
    {
        foreach ($db->getIndexData(self::TABLE) as $index) {
            if ($index->name === $name) {
                return true;
            }
        }

        return false;
    }
}
