<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use Config\Database;

/**
 * `project_files` — metadata for files uploaded to a project (see master
 * plan Data Model #7). Actual bytes live under
 * `writable/uploads/projects/{project_id}/{stored_name}`; this table is the
 * index. Soft-delete. Guarded no-op if the table already exists.
 */
class CreateProjectFiles extends Migration
{
    public function up(): void
    {
        $db = Database::connect($this->DBGroup);
        $db->resetDataCache();

        if ($db->tableExists('project_files')) {
            return;
        }

        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'project_id' => [
                'type'     => 'INT',
                'unsigned' => true,
            ],
            'uploaded_by' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
            ],
            'original_name' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
            ],
            'stored_name' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
            ],
            'mime' => [
                'type'       => 'VARCHAR',
                'constraint' => 150,
            ],
            'size' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'deleted_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('project_id', false, false, 'idx_project_files_project');
        $this->forge->createTable('project_files');
    }

    public function down(): void
    {
        $testsDatabase = (string) (config(Database::class)->tests['database'] ?? '');

        if ($testsDatabase !== '' && $this->db->getDatabase() === $testsDatabase) {
            $this->forge->dropTable('project_files', true);
        }
    }
}
