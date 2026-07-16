<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use Config\Database;

/**
 * Idempotent: on a greenfield/test DB it creates the full table; on a DB that
 * already has `users_api_access_keys` (e.g. imported live data) it skips the
 * create and instead adds any proxyv2-specific columns the imported schema is
 * missing. See CreateUsers::up() for why resetDataCache() precedes
 * tableExists() (stale cache across the test regress()+latest() cycle).
 */
class CreateUsersApiAccessKeys extends Migration
{
    /**
     * Single source of the column definitions — reused by the fresh-create
     * path and the add-missing-column path so the two can't drift.
     *
     * @return array<string, array<string, mixed>>
     */
    private function fields(): array
    {
        return [
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
        ];
    }

    public function up(): void
    {
        $db = Database::connect($this->DBGroup);
        $db->resetDataCache();

        if (! $db->tableExists('users_api_access_keys')) {
            $this->forge->addField($this->fields());
            $this->forge->addPrimaryKey('id');
            $this->forge->addUniqueKey('access_key');
            $this->forge->createTable('users_api_access_keys');

            return;
        }

        // Pre-existing (imported) table: bring it up to the proxyv2 schema by
        // adding only the columns it is missing. `allowed_ips` is owned by its
        // own later migration, so it is intentionally not listed here.
        foreach ($this->fields() as $name => $definition) {
            if ($name === 'id') {
                continue;
            }

            if (! $db->fieldExists($name, 'users_api_access_keys')) {
                $this->forge->addColumn('users_api_access_keys', [$name => $definition]);
            }
        }
    }

    public function down(): void
    {
        // Safe-on-live guard: only ever drop when connected to the dedicated
        // `tests` DB group — never nuke imported/live data on rollback.
        $testsDatabase = (string) (config(Database::class)->tests['database'] ?? '');

        if ($testsDatabase !== '' && $this->db->getDatabase() === $testsDatabase) {
            $this->forge->dropTable('users_api_access_keys', true);
        }
    }
}
