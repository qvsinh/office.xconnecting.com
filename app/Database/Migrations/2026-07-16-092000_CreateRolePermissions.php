<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use Config\Database;

/**
 * `role_permissions` — join table granting a `permissions` row to a `roles`
 * row. `UNIQUE(role_id, permission_id)` backs `RoleModel::syncPermissions()`
 * (delete+reinsert, never a duplicate grant). Guarded so it's a no-op if the
 * table already exists (mirrors CreateNotifications).
 */
class CreateRolePermissions extends Migration
{
    public function up(): void
    {
        $db = Database::connect($this->DBGroup);
        $db->resetDataCache();

        if ($db->tableExists('role_permissions')) {
            return;
        }

        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'role_id' => [
                'type'     => 'INT',
                'unsigned' => true,
            ],
            'permission_id' => [
                'type'     => 'INT',
                'unsigned' => true,
            ],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['role_id', 'permission_id']);
        // Covers RoleModel::permissionsFor()'s `WHERE role_id=?` join lookup.
        $this->forge->addKey('permission_id', false, false, 'idx_role_permissions_permission');
        $this->forge->createTable('role_permissions');
    }

    public function down(): void
    {
        $testsDatabase = (string) (config(Database::class)->tests['database'] ?? '');

        if ($testsDatabase !== '' && $this->db->getDatabase() === $testsDatabase) {
            $this->forge->dropTable('role_permissions', true);
        }
    }
}
