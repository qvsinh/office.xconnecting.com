<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use Config\Database;

/**
 * `permissions` — dotted names (e.g. `users.manage`) granted to roles via
 * `role_permissions`. Guarded so it's a no-op if the table already exists
 * (mirrors CreateNotifications).
 */
class CreatePermissions extends Migration
{
    public function up(): void
    {
        $db = Database::connect($this->DBGroup);
        $db->resetDataCache();

        if ($db->tableExists('permissions')) {
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
                'constraint' => 100,
            ],
            'label' => [
                'type'       => 'VARCHAR',
                'constraint' => 150,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('name');
        $this->forge->createTable('permissions');
    }

    public function down(): void
    {
        $testsDatabase = (string) (config(Database::class)->tests['database'] ?? '');

        if ($testsDatabase !== '' && $this->db->getDatabase() === $testsDatabase) {
            $this->forge->dropTable('permissions', true);
        }
    }
}
