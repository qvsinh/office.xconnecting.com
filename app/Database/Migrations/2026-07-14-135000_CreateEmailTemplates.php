<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use Config\Database;

/**
 * `email_templates` already exists on dev/prod (confirmed via `SHOW CREATE
 * TABLE email_templates` against db_proxy_dev — see
 * kanban/processing/20260714-admin-emails.md §5) so `up()` is a guarded
 * no-op there. On a greenfield/tests DB this creates a schema-compatible
 * table. No `updated_at` column (matches the live schema).
 */
class CreateEmailTemplates extends Migration
{
    public function up(): void
    {
        // See CreateUsers::up() for why resetDataCache() must run before
        // tableExists() — a long-lived connection re-running this migration
        // across the test suite's regress()+latest() cycle can otherwise see
        // a stale "still exists" result and silently skip recreation.
        $db = Database::connect($this->DBGroup);
        $db->resetDataCache();

        if ($db->tableExists('email_templates')) {
            return;
        }

        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'auto_increment' => true,
            ],
            'name' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
            ],
            'lang_code' => [
                'type'       => 'VARCHAR',
                'constraint' => 2,
                'null'       => true,
            ],
            'subject' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
            ],
            'content' => [
                'type' => 'LONGTEXT',
                'null' => true,
            ],
            'active' => [
                'type'    => 'INT',
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
        // Composite-unique key `(name, lang_code)` — matches the live `lang_UN`
        // key and backstops EmailTemplateModel::existsForNameLang()'s manual
        // pre-check against a race.
        $this->forge->addUniqueKey(['name', 'lang_code'], 'lang_UN');
        $this->forge->createTable('email_templates');
    }

    public function down(): void
    {
        // Safe-on-live guard: only ever drop when connected to the dedicated
        // `tests` DB group — see CreateUsers::down() for the full rationale.
        $testsDatabase = (string) (config(Database::class)->tests['database'] ?? '');

        if ($testsDatabase !== '' && $this->db->getDatabase() === $testsDatabase) {
            $this->forge->dropTable('email_templates', true);
        }
    }
}
