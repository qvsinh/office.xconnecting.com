<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use Config\Database;

/**
 * `users.zone`: `global` -> `admin`, `client` -> `user`.
 *
 * The legacy names came from the ported proxy schema and never described this
 * app: there is no "global" scope here, only administrators and employees. The
 * value is the app's authorisation gate (`is_admin()` / `AdminFilter`), so the
 * rename is a data migration plus a code change, not a display tweak.
 *
 * Also moves the column default from `client` to `user`, so a row inserted
 * without an explicit zone lands on the new vocabulary.
 *
 * Idempotent: re-running only touches rows still holding a legacy value.
 *
 * NOTE for the deploy: sessions cache the user row, so anyone signed in at the
 * moment this runs still carries `global`/`client` in their session until they
 * sign in again. `is_admin()` therefore accepts the legacy value as well — see
 * the `LEGACY_ADMIN_ZONE` note in `app/Helpers/auth_helper.php`, which is safe
 * to delete once every session has rolled over.
 */
class RenameUserZoneValues extends Migration
{
    /**
     * `legacy => new`.
     */
    private const RENAMES = [
        'global' => 'admin',
        'client' => 'user',
    ];

    public function up(): void
    {
        // See CreateUsers::up() for why resetDataCache() is needed before schema
        // introspection on a long-lived connection re-running migrations.
        $db = Database::connect($this->DBGroup);
        $db->resetDataCache();

        if (! $db->tableExists('users')) {
            return;
        }

        foreach (self::RENAMES as $legacy => $current) {
            $db->table('users')->where('zone', $legacy)->update(['zone' => $current]);
        }

        // Bring the column default in line with the new vocabulary.
        $this->forge->modifyColumn('users', [
            'zone' => [
                'name'       => 'zone',
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'default'    => 'user',
            ],
        ]);
    }

    public function down(): void
    {
        // Safe-on-live guard: only ever mutate data when connected to the
        // dedicated `tests` DB group — see CreateUsers::down() for the rationale.
        $testsDatabase = (string) (config(Database::class)->tests['database'] ?? '');

        if ($testsDatabase === '' || $this->db->getDatabase() !== $testsDatabase) {
            return;
        }

        foreach (self::RENAMES as $legacy => $current) {
            $this->db->table('users')->where('zone', $current)->update(['zone' => $legacy]);
        }

        $this->forge->modifyColumn('users', [
            'zone' => [
                'name'       => 'zone',
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'default'    => 'client',
            ],
        ]);
    }
}
