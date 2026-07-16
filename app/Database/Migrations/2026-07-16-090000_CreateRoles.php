<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use Config\Database;

/**
 * `roles` — the additive roles+permissions scaffold (see kanban plan). Guarded
 * so it's a no-op if the table already exists (mirrors CreateNotifications).
 */
class CreateRoles extends Migration
{
    public function up(): void
    {
        // See CreateUsers::up() for why resetDataCache() must run before
        // tableExists() on a long-lived connection (test suite regress()+latest()
        // cycle).
        $db = Database::connect($this->DBGroup);
        $db->resetDataCache();

        if ($db->tableExists('roles')) {
            return;
        }

        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'name' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
            ],
            'label' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('name');
        $this->forge->createTable('roles');
    }

    public function down(): void
    {
        // Safe-on-live guard: only ever drop when connected to the
        // dedicated `tests` DB group — see CreateUsers::down().
        $testsDatabase = (string) (config(Database::class)->tests['database'] ?? '');

        if ($testsDatabase !== '' && $this->db->getDatabase() === $testsDatabase) {
            $this->forge->dropTable('roles', true);
        }
    }
}
