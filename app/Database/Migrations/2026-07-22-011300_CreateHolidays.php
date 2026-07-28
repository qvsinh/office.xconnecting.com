<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use Config\Database;

/**
 * `holidays` — company non-working days, so an empty attendance row on Tết reads
 * as `holiday` rather than `absent`.
 *
 * A flat date list with no recurrence rule, deliberately: Vietnamese public
 * holidays are largely lunar (Tết, Hùng Kings) and the compensating-day-off
 * arrangement is announced annually, so a stored RRULE would be wrong more often
 * than right. An admin pastes the year's list in once.
 */
class CreateHolidays extends Migration
{
    public function up(): void
    {
        // See CreateUsers::up() for why resetDataCache() is required before
        // tableExists() on a long-lived connection re-running migrations.
        $db = Database::connect($this->DBGroup);
        $db->resetDataCache();

        if ($db->tableExists('holidays')) {
            return;
        }

        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 10,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'holiday_date' => [
                'type' => 'DATE',
            ],
            'name' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
        $this->forge->addPrimaryKey('id');
        // One holiday per date — a duplicate paste is reported, not stored twice.
        $this->forge->addUniqueKey('holiday_date');
        $this->forge->createTable('holidays');
    }

    public function down(): void
    {
        // Safe-on-live guard: only ever drop when connected to the dedicated
        // `tests` DB group — see CreateUsers::down() for the full rationale.
        $testsDatabase = (string) (config(Database::class)->tests['database'] ?? '');

        if ($testsDatabase !== '' && $this->db->getDatabase() === $testsDatabase) {
            $this->forge->dropTable('holidays', true);
        }
    }
}
