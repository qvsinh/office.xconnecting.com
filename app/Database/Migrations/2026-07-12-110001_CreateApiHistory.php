<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use Config\Database;

/**
 * Idempotent: creates the full `api_history` table on a greenfield/test DB;
 * on a DB that already has it (imported live data) it skips the create and
 * adds any missing columns. Indexes are only laid down on a fresh create —
 * an imported table keeps whatever indexes it already has (adding indexes to
 * a large existing history table is a deliberate, separate operation).
 */
class CreateApiHistory extends Migration
{
    /**
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
        ];
    }

    public function up(): void
    {
        $db = Database::connect($this->DBGroup);
        $db->resetDataCache();

        if (! $db->tableExists('api_history')) {
            $this->forge->addField($this->fields());
            $this->forge->addPrimaryKey('id');
            // Composite covers fetchHistory's service_name+service_id scoping (leftmost-prefix rule).
            $this->forge->addKey(['service_name', 'service_id'], false, false, 'idx_api_history_service');
            $this->forge->addKey('command');
            $this->forge->addKey('env');
            $this->forge->createTable('api_history');

            return;
        }

        // Pre-existing (imported) table: add only the columns it is missing.
        foreach ($this->fields() as $name => $definition) {
            if ($name === 'id') {
                continue;
            }

            if (! $db->fieldExists($name, 'api_history')) {
                $this->forge->addColumn('api_history', [$name => $definition]);
            }
        }
    }

    public function down(): void
    {
        // Safe-on-live guard: only ever drop when connected to the dedicated
        // `tests` DB group.
        $testsDatabase = (string) (config(Database::class)->tests['database'] ?? '');

        if ($testsDatabase !== '' && $this->db->getDatabase() === $testsDatabase) {
            $this->forge->dropTable('api_history', true);
        }
    }
}
