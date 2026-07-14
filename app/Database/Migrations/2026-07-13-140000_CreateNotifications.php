<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use Config\Database;

/**
 * Idempotent by design: `db_proxy_dev` (and prod) already have a
 * `notifications` table, so `up()` is a guarded no-op there. On a
 * greenfield/test DB this creates a schema-compatible table.
 */
class CreateNotifications extends Migration
{
    public function up(): void
    {
        // See CreateUsers::up() for why resetDataCache() must run before
        // tableExists() — a long-lived connection re-running this migration
        // across the test suite's regress()+latest() cycle can otherwise see
        // a stale "still exists" result and silently skip recreation.
        $db = Database::connect($this->DBGroup);
        $db->resetDataCache();

        if ($db->tableExists('notifications')) {
            return;
        }

        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'user_id' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => true,
            ],
            'task_id' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => true,
            ],
            'message' => [
                'type' => 'TEXT',
                'null' => false,
            ],
            'is_read' => [
                'type'    => 'TINYINT',
                'constraint' => 1,
                'default' => 0,
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
        // Covers listForUser's `WHERE user_id=? ORDER BY created_at DESC`.
        $this->forge->addKey(['user_id', 'created_at'], false, false, 'idx_notifications_user_created');
        // Covers unreadCountForUser's `WHERE user_id=? AND is_read=0`.
        $this->forge->addKey(['user_id', 'is_read'], false, false, 'idx_notifications_user_read');
        $this->forge->createTable('notifications');
    }

    public function down(): void
    {
        // Safe-on-live guard: only ever drop when connected to the
        // dedicated `tests` DB group — see CreateUsers::down().
        $testsDatabase = (string) (config(Database::class)->tests['database'] ?? '');

        if ($testsDatabase !== '' && $this->db->getDatabase() === $testsDatabase) {
            $this->forge->dropTable('notifications', true);
        }
    }
}
