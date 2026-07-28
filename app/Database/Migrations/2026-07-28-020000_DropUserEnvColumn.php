<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use Config\Database;

/**
 * Drops `users.env` (`LIVE` / `TESTING`).
 *
 * A leftover from the starter, where an account belonged to either the live or
 * the testing side of an external API. This app is one office's attendance
 * system: there is one environment, so the column only ever added a field to
 * every form that nobody could answer meaningfully.
 *
 * Nothing read it for a decision — no filter, no gate, no query. `countByEnv()`
 * existed but was called by its own test and nothing else.
 *
 * Guarded so a fresh install (or a re-run) is a no-op.
 */
class DropUserEnvColumn extends Migration
{
    public function up(): void
    {
        // See CreateUsers::up() for why resetDataCache() is required before
        // schema introspection on a long-lived connection.
        $db = Database::connect($this->DBGroup);
        $db->resetDataCache();

        if ($db->fieldExists('env', 'users')) {
            $this->forge->dropColumn('users', 'env');
        }
    }

    /**
     * Deliberately restores the column but not its values: the data carried no
     * meaning in this app, so inventing one on rollback would be worse than a
     * default. Rolling back leaves every user on `LIVE`.
     */
    public function down(): void
    {
        $db = Database::connect($this->DBGroup);
        $db->resetDataCache();

        if ($db->fieldExists('env', 'users')) {
            return;
        }

        $this->forge->addColumn('users', [
            'env' => [
                'type'       => 'VARCHAR',
                'constraint' => 10,
                'default'    => 'LIVE',
                'after'      => 'zone',
            ],
        ]);
    }
}
