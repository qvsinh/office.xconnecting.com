<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use Config\Database;

/**
 * Idempotent by design: `db_proxy_dev` (and prod) already have a `users`
 * table with real accounts, so `up()` is a guarded no-op there. On a
 * greenfield/test DB (e.g. the SQLite `tests` group) this creates a
 * schema-compatible table matching the live `db_proxy_dev.users` schema.
 */
class CreateUsers extends Migration
{
    public function up(): void
    {
        // Migration::$db is typed as the generic ConnectionInterface, which
        // doesn't declare tableExists() — go through Database::connect()
        // (typed @return BaseConnection) for the same connection instead.
        if (Database::connect($this->DBGroup)->tableExists('users')) {
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
                'null'       => true,
            ],
            'email' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
            ],
            'password' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
            ],
            'two_factor_method' => [
                'type'       => 'ENUM',
                'constraint' => ['none', 'google2fa', 'email_otp'],
                'default'    => 'none',
            ],
            'google2fa_secret' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'zone' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'default'    => 'client',
            ],
            'env' => [
                'type'       => 'ENUM',
                'constraint' => ['LIVE', 'TESTING'],
                'default'    => 'TESTING',
            ],
            'role' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'null'       => true,
                'default'    => 'admin',
            ],
            'language' => [
                'type'       => 'VARCHAR',
                'constraint' => 5,
                'default'    => 'ja',
            ],
            'timezone' => [
                'type'       => 'VARCHAR',
                'constraint' => 30,
                'null'       => true,
                'default'    => 'Asia/Tokyo',
            ],
            'currency' => [
                'type'       => 'VARCHAR',
                'constraint' => 10,
                'null'       => true,
                'default'    => 'USD',
            ],
            'first_name' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'last_name' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'company' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'status' => [
                'type'    => 'INT',
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
            'deleted_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'login_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('email');
        $this->forge->createTable('users');
    }

    public function down(): void
    {
        // Safe-on-live guard: only ever drop when connected to the
        // dedicated `tests` DB group (the disposable sandbox — see
        // Config\Database's own "never overwrite live data" comment).
        // We can't otherwise tell "this migration created the table" apart
        // from "it already existed" (e.g. live db_proxy_dev), so on any
        // other group this is a no-op.
        $testsDatabase = (string) (config(Database::class)->tests['database'] ?? '');

        if ($testsDatabase !== '' && $this->db->getDatabase() === $testsDatabase) {
            $this->forge->dropTable('users', true);
        }
    }
}
