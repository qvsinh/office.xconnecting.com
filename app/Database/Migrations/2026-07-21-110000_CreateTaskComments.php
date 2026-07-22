<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use Config\Database;

/**
 * `task_comments` — a flat comment thread on a task. Guarded (no-op if the
 * table already exists) with a tests-DB-only `down()`, matching the other
 * social-hub migrations.
 */
class CreateTaskComments extends Migration
{
    public function up(): void
    {
        $db = Database::connect($this->DBGroup);
        $db->resetDataCache();

        if ($db->tableExists('task_comments')) {
            return;
        }

        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'task_id' => [
                'type'     => 'INT',
                'unsigned' => true,
            ],
            'user_id' => [
                'type' => 'BIGINT',
            ],
            'body' => [
                'type' => 'TEXT',
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('task_id', false, false, 'idx_task_comments_task');
        $this->forge->createTable('task_comments');
    }

    public function down(): void
    {
        $testsDatabase = (string) (config(Database::class)->tests['database'] ?? '');

        if ($testsDatabase !== '' && $this->db->getDatabase() === $testsDatabase) {
            $this->forge->dropTable('task_comments', true);
        }
    }
}
