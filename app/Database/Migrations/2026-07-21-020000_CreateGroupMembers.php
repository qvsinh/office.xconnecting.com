<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use Config\Database;

/**
 * `group_members` — join table linking `user_groups` to `users`. No FK
 * constraints per project convention (starter doesn't use them) — just
 * indexes on the columns every lookup filters by. Guarded no-op if the table
 * already exists.
 */
class CreateGroupMembers extends Migration
{
    public function up(): void
    {
        $db = Database::connect($this->DBGroup);
        $db->resetDataCache();

        if ($db->tableExists('group_members')) {
            return;
        }

        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'group_id' => [
                'type'     => 'INT',
                'unsigned' => true,
            ],
            'user_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['group_id', 'user_id']);
        $this->forge->addKey('user_id', false, false, 'idx_group_members_user');
        $this->forge->createTable('group_members');
    }

    public function down(): void
    {
        $testsDatabase = (string) (config(Database::class)->tests['database'] ?? '');

        if ($testsDatabase !== '' && $this->db->getDatabase() === $testsDatabase) {
            $this->forge->dropTable('group_members', true);
        }
    }
}
