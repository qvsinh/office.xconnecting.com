<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use Config\Database;

/**
 * `servers` does not exist on any environment yet (dev/prod/test) — this
 * genuinely creates the table. The `tableExists()` guard is kept anyway for
 * safety and to match the idempotent pattern used by every other migration
 * here (see `CreateUsers`), including the test suite's regress()+latest()
 * cycle between test methods.
 */
class CreateServers extends Migration
{
    public function up(): void
    {
        // See CreateUsers::up() for why resetDataCache() is required before
        // tableExists() on a long-lived connection re-running migrations.
        $db = Database::connect($this->DBGroup);
        $db->resetDataCache();

        if ($db->tableExists('servers')) {
            return;
        }

        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'auto_increment' => true,
            ],
            'name' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'ip' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'status' => [
                'type'    => 'INT',
                'default' => 1,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'deleted_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
        $this->forge->addPrimaryKey('id');
        // The admin list orders/filters by status (ServerModel::searchAdmin()).
        $this->forge->addKey('status');
        $this->forge->createTable('servers');
    }

    public function down(): void
    {
        // Safe-on-live guard: only ever drop when connected to the dedicated
        // `tests` DB group — see CreateUsers::down() for the full rationale.
        $testsDatabase = (string) (config(Database::class)->tests['database'] ?? '');

        if ($testsDatabase !== '' && $this->db->getDatabase() === $testsDatabase) {
            $this->forge->dropTable('servers', true);
        }
    }
}
