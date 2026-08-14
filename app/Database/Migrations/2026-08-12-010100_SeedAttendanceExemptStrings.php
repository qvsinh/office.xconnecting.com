<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use Config\Database;

/**
 * The one string the attendance exemption needs on the employee's own page.
 *
 * A separate migration rather than an edit to `SeedInterfaceTranslations`: that
 * one has already run everywhere, so editing it would only ever help a fresh
 * install. `TranslationCoverageTest` fails when a `lang()` key has no row in some
 * language — a missing key does not error, it renders as the literal
 * `attendance.blocked_exempt`.
 *
 * Idempotent per (lang_code, location, slug), so it is safe on a live database
 * and re-runnable.
 */
class SeedAttendanceExemptStrings extends Migration
{
    private const LOCATION = 'attendance';

    /**
     * @var array<string, array{string, string}>
     */
    private const STRINGS = [
        'blocked_exempt' => [
            'This account does not record check-in or check-out.',
            'Tài khoản này không cần chấm công vào/ra.',
        ],
    ];

    public function up(): void
    {
        $db = Database::connect($this->DBGroup);
        $db->resetDataCache();

        if (! $db->tableExists('translation')) {
            return;
        }

        foreach (self::STRINGS as $slug => [$en, $vi]) {
            foreach (['en' => $en, 'vi' => $vi] as $lang => $content) {
                $exists = $db->table('translation')
                    ->where('lang_code', $lang)
                    ->where('location', self::LOCATION)
                    ->where('slug', $slug)
                    ->countAllResults() > 0;

                if ($exists) {
                    continue;
                }

                $db->table('translation')->insert([
                    'lang_code' => $lang,
                    'location'  => self::LOCATION,
                    'slug'      => $slug,
                    'content'   => $content,
                ]);
            }
        }
    }

    /**
     * Safe-on-live guard: only delete on the dedicated tests DB group, so a
     * rollback cannot throw away a string an administrator has edited.
     */
    public function down(): void
    {
        $testsDatabase = (string) (config(Database::class)->tests['database'] ?? '');

        if ($testsDatabase === '' || $this->db->getDatabase() !== $testsDatabase) {
            return;
        }

        $this->db->table('translation')
            ->where('location', self::LOCATION)
            ->whereIn('slug', array_keys(self::STRINGS))
            ->delete();
    }
}
