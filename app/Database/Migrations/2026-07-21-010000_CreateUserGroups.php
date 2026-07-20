<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use Config\Database;

/**
 * `user_groups` — a named, admin-managed set of users that can be bulk-added
 * to a workspace/project (see `kanban/processing/20260721-00-master-social-hub.md`
 * Data Model #1). Named `user_groups`, not `groups` — `GROUPS` is a reserved
 * word in MySQL 8. Guarded no-op if the table already exists (mirrors
 * `CreateSiteVariable`).
 */
class CreateUserGroups extends Migration
{
    public function up(): void
    {
        // See CreateUsers::up() for why resetDataCache() is required before
        // tableExists() on a long-lived connection re-running migrations.
        $db = Database::connect($this->DBGroup);
        $db->resetDataCache();

        if ($db->tableExists('user_groups')) {
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
                'constraint' => 150,
            ],
            'slug' => [
                'type'       => 'VARCHAR',
                'constraint' => 160,
            ],
            'description' => [
                'type' => 'TEXT',
                'null' => true,
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
        $this->forge->addUniqueKey('slug');
        $this->forge->createTable('user_groups');
    }

    public function down(): void
    {
        // Safe-on-live guard: only ever drop when connected to the dedicated
        // `tests` DB group — see CreateUsers::down() for the full rationale.
        $testsDatabase = (string) (config(Database::class)->tests['database'] ?? '');

        if ($testsDatabase !== '' && $this->db->getDatabase() === $testsDatabase) {
            $this->forge->dropTable('user_groups', true);
        }
    }
}
