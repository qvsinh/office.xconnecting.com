<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;
use Config\Database;

/**
 * Session store table for the CI4 DatabaseHandler (schema per the CI4 4.7 user
 * guide). Idempotent: skips creation if `ci_sessions` already exists (e.g.
 * imported data or a prior manual create), so it's safe on live and re-runnable.
 * Only needed when `session.driver` = DatabaseHandler; harmless otherwise.
 */
class CreateSessionsTable extends Migration
{
    private const TABLE = 'ci_sessions';

    public function up(): void
    {
        $db = Database::connect($this->DBGroup);
        $db->resetDataCache();

        if ($db->tableExists(self::TABLE)) {
            return;
        }

        $this->forge->addField([
            'id' => [
                'type'       => 'VARCHAR',
                'constraint' => 128,
                'null'       => false,
            ],
            'ip_address' => [
                'type'       => 'VARCHAR',
                'constraint' => 45,
                'null'       => false,
            ],
            'timestamp' => [
                'type'    => 'TIMESTAMP',
                'null'    => false,
                'default' => new RawSql('CURRENT_TIMESTAMP'),
            ],
            'data' => [
                'type' => 'BLOB',
                'null' => false,
            ],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('timestamp', false, false, 'ci_sessions_timestamp');
        $this->forge->createTable(self::TABLE);
    }

    public function down(): void
    {
        // Safe-on-live guard: only ever drop on the dedicated tests DB group.
        $testsDatabase = (string) (config(Database::class)->tests['database'] ?? '');

        if ($testsDatabase !== '' && $this->db->getDatabase() === $testsDatabase) {
            $this->forge->dropTable(self::TABLE, true);
        }
    }
}
