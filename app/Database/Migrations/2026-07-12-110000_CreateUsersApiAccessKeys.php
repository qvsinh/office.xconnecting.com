<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateUsersApiAccessKeys extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'user_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => false,
            ],
            'access_key' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
            ],
            'user_status' => [
                'type'     => 'TINYINT',
                'unsigned' => true,
                'null'     => true,
            ],
            'service_id' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => true,
            ],
            'is_enable' => [
                'type'     => 'TINYINT',
                'unsigned' => true,
                'null'     => false,
                'default'  => 1,
            ],
            'enable_log' => [
                'type'     => 'TINYINT',
                'unsigned' => true,
                'null'     => false,
                'default'  => 1,
            ],
            'description' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'last_access' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('access_key');
        $this->forge->createTable('users_api_access_keys');
    }

    public function down(): void
    {
        $this->forge->dropTable('users_api_access_keys');
    }
}
