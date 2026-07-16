<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use Config\Database;

/**
 * Seed the admin-editable `site_name` site variable (used in page <title>
 * tags via the `site_name()` helper). Idempotent: only inserts when the
 * `site_variable` table exists and no `site_name` row is present yet, so it's
 * safe on an imported/live DB and re-runnable.
 */
class SeedSiteNameVariable extends Migration
{
    public function up(): void
    {
        $db = Database::connect($this->DBGroup);
        $db->resetDataCache();

        if (! $db->tableExists('site_variable')) {
            return;
        }

        // Count including any soft-deleted row — the UNIQUE(name) constraint
        // covers those too, so this avoids a duplicate-key insert.
        $exists = $db->table('site_variable')->where('name', 'site_name')->countAllResults() > 0;

        if ($exists) {
            return;
        }

        $db->table('site_variable')->insert([
            'name'        => 'site_name',
            'value'       => 'xproxy.psi.jp',
            'type'        => 'string',
            'description' => 'Site/brand name shown in page titles.',
            'created_at'  => date('Y-m-d H:i:s'),
        ]);
    }

    public function down(): void
    {
        // Safe-on-live guard: only touch data on the dedicated tests DB group.
        $testsDatabase = (string) (config(Database::class)->tests['database'] ?? '');

        if ($testsDatabase !== '' && $this->db->getDatabase() === $testsDatabase) {
            $this->db->table('site_variable')->where('name', 'site_name')->delete();
        }
    }
}
