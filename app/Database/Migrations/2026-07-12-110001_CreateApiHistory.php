<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateApiHistory extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'env' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
                'null'       => true,
            ],
            'service_name' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
                'null'       => true,
            ],
            'service_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => true,
            ],
            'user_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => true,
            ],
            'url' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'command' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'domain' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'params' => [
                'type' => 'MEDIUMTEXT',
                'null' => true,
            ],
            'response' => [
                'type' => 'LONGTEXT',
                'null' => true,
            ],
            'metadata' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'status' => [
                'type'    => 'INT',
                'null'    => true,
                'default' => 1,
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
        // Composite covers fetchHistory's service_name+service_id scoping (leftmost-prefix rule).
        $this->forge->addKey(['service_name', 'service_id'], false, false, 'idx_api_history_service');
        $this->forge->addKey('command');
        $this->forge->addKey('env');
        $this->forge->createTable('api_history');
    }

    public function down(): void
    {
        $this->forge->dropTable('api_history');
    }
}
