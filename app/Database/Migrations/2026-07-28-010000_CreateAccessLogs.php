<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use Config\Database;

/**
 * `access_logs` — who did what, from where, on which device.
 *
 * One table for all three events (login / check-in / check-out) rather than more
 * columns on `attendance_days`: a login has no attendance row to hang off, and
 * one shape means one query, one screen and one retention rule.
 *
 * `attendance_days.checkin_ip` / `checkout_ip` stay where they are — they back
 * the office-IP gate and the attendance UI. This table is the fuller context
 * beside them, not a replacement.
 *
 * **This is personal data.** It is admin-only in the UI and pruned by the
 * `access_logs.purge` scheduled handler; do not widen either without a reason.
 */
class CreateAccessLogs extends Migration
{
    public function up(): void
    {
        // See CreateUsers::up() for why resetDataCache() is required before
        // tableExists() on a long-lived connection re-running migrations.
        $db = Database::connect($this->DBGroup);
        $db->resetDataCache();

        if ($db->tableExists('access_logs')) {
            return;
        }

        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 10,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'user_id' => [
                'type'       => 'BIGINT',
                'constraint' => 20,
            ],
            // login | checkin | checkout
            'event' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
            ],
            'ip' => [
                'type'       => 'VARCHAR',
                'constraint' => 45,
                'null'       => true,
            ],
            // The raw header, kept verbatim so a better parser can be run over
            // the history later — the parsed columns below are a convenience,
            // not the record of truth.
            'user_agent' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'browser' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
                'null'       => true,
            ],
            'platform' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
                'null'       => true,
            ],
            // desktop | mobile | robot — see AccessLogger::deviceType() for why
            // tablets are not split out.
            'device_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'null'       => true,
            ],
            // Client-reported, therefore untrusted: diagnostics only, never a
            // security signal. Two ints rather than "1920x1080" so the column
            // can actually be filtered and sorted.
            'screen_width' => [
                'type'       => 'SMALLINT',
                'constraint' => 5,
                'unsigned'   => true,
                'null'       => true,
            ],
            'screen_height' => [
                'type'       => 'SMALLINT',
                'constraint' => 5,
                'unsigned'   => true,
                'null'       => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
        $this->forge->addPrimaryKey('id');
        // The two access paths: "everything this person did" and "everything that
        // happened recently", which is how the admin screen reads it.
        $this->forge->addKey(['user_id', 'created_at']);
        $this->forge->addKey('created_at');
        $this->forge->addKey('event');
        $this->forge->createTable('access_logs');
    }

    public function down(): void
    {
        // Safe-on-live guard: only ever drop when connected to the dedicated
        // `tests` DB group — see CreateUsers::down() for the full rationale.
        $testsDatabase = (string) (config(Database::class)->tests['database'] ?? '');

        if ($testsDatabase !== '' && $this->db->getDatabase() === $testsDatabase) {
            $this->forge->dropTable('access_logs', true);
        }
    }
}
