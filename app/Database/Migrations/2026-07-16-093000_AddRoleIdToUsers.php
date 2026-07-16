<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use Config\Database;

/**
 * Adds nullable `role_id` (BIGINT unsigned, no FK constraint — mirrors the
 * rest of this schema's style) to `users`, pointing at `roles.id`. Guarded
 * so it's a no-op where the column already exists (mirrors
 * AddPhoneNumberToUsers). Backfilled by SeedRolesAndPermissions.
 */
class AddRoleIdToUsers extends Migration
{
    public function up(): void
    {
        if ($this->columnExists()) {
            return;
        }

        $this->forge->addColumn('users', [
            'role_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => true,
                'after'    => 'role',
            ],
        ]);
        // ponytail: no index — nothing filters users by role_id yet
        // (user_can() looks up a role_id it already has, not the reverse).
        // Add one when a query actually does WHERE role_id = ?.
    }

    public function down(): void
    {
        if (! $this->columnExists()) {
            return;
        }

        $this->forge->dropColumn('users', 'role_id');
    }

    private function columnExists(): bool
    {
        // See AddPhoneNumberToUsers for why resetDataCache() is required.
        $db = Database::connect($this->DBGroup);
        $db->resetDataCache();

        return $db->fieldExists('role_id', 'users');
    }
}
