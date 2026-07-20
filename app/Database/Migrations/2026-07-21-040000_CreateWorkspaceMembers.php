<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use Config\Database;

/**
 * `workspace_members` — join table granting a `users` row a role
 * (owner/admin/member/viewer) inside a `workspaces` row. Backs
 * `WorkspaceMemberModel::roleFor()` / `access_helper.php::workspace_role()`.
 * Guarded no-op if the table already exists.
 */
class CreateWorkspaceMembers extends Migration
{
    public function up(): void
    {
        $db = Database::connect($this->DBGroup);
        $db->resetDataCache();

        if ($db->tableExists('workspace_members')) {
            return;
        }

        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'workspace_id' => [
                'type'     => 'INT',
                'unsigned' => true,
            ],
            'user_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
            ],
            'role' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'default'    => 'member',
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['workspace_id', 'user_id']);
        $this->forge->addKey('user_id', false, false, 'idx_workspace_members_user');
        $this->forge->createTable('workspace_members');
    }

    public function down(): void
    {
        $testsDatabase = (string) (config(Database::class)->tests['database'] ?? '');

        if ($testsDatabase !== '' && $this->db->getDatabase() === $testsDatabase) {
            $this->forge->dropTable('workspace_members', true);
        }
    }
}
