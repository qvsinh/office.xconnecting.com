<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use Config\Database;

/**
 * `translation` already exists on dev/prod (confirmed via `SHOW COLUMNS FROM
 * translation` / `SHOW CREATE TABLE translation` against db_proxy_dev — see
 * kanban/processing/20260714-admin-translations.md §7) so `up()` is a guarded
 * no-op there. On a greenfield/tests DB this creates a schema-compatible
 * table. No `created_at`/`deleted_at` columns (matches the live schema).
 */
class CreateTranslation extends Migration
{
    public function up(): void
    {
        // See CreateUsers::up() for why resetDataCache() is required before
        // tableExists() on a long-lived connection re-running migrations.
        $db = Database::connect($this->DBGroup);
        $db->resetDataCache();

        if ($db->tableExists('translation')) {
            return;
        }

        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'constraint'     => 20,
                'auto_increment' => true,
            ],
            'lang_code' => [
                'type'       => 'VARCHAR',
                'constraint' => 5,
            ],
            'location' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'slug' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
            ],
            'content' => [
                'type' => 'TEXT',
            ],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['lang_code', 'slug']);
        $this->forge->createTable('translation');
    }

    public function down(): void
    {
        // Safe-on-live guard: only ever drop when connected to the dedicated
        // `tests` DB group — see CreateUsers::down() for the full rationale.
        $testsDatabase = (string) (config(Database::class)->tests['database'] ?? '');

        if ($testsDatabase !== '' && $this->db->getDatabase() === $testsDatabase) {
            $this->forge->dropTable('translation', true);
        }
    }
}
