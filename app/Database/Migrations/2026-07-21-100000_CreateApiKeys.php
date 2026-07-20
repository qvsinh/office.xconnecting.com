<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use Config\Database;

/**
 * `api_keys` — personal API keys for the REST API (see master plan Data
 * Model #10). Only `token_prefix` + `token_hash` are ever stored; the
 * plaintext token is shown once at creation time and never persisted — see
 * `ApiKeyModel::createForUser()`/`resolve()`. Guarded no-op if the table
 * already exists.
 */
class CreateApiKeys extends Migration
{
    public function up(): void
    {
        $db = Database::connect($this->DBGroup);
        $db->resetDataCache();

        if ($db->tableExists('api_keys')) {
            return;
        }

        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'user_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
            ],
            'name' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
            ],
            'token_prefix' => [
                'type'       => 'VARCHAR',
                'constraint' => 16,
            ],
            'token_hash' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
            ],
            'scopes' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'default'    => 'read',
            ],
            'last_used_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'expires_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'revoked_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('token_prefix');
        $this->forge->addKey('user_id', false, false, 'idx_api_keys_user');
        $this->forge->createTable('api_keys');
    }

    public function down(): void
    {
        $testsDatabase = (string) (config(Database::class)->tests['database'] ?? '');

        if ($testsDatabase !== '' && $this->db->getDatabase() === $testsDatabase) {
            $this->forge->dropTable('api_keys', true);
        }
    }
}
