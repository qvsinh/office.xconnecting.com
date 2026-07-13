<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use Config\Database;

/**
 * Backs the per-user list/count queries (`ApiAccessKeyModel::listForUser()`,
 * `countForUser()`) added for the API access key management UI. Guarded
 * against re-running on a DB that already has the index.
 */
class AddUserIdIndexToUsersApiAccessKeys extends Migration
{
    private const INDEX_NAME = 'idx_uak_user_id';

    public function up(): void
    {
        if ($this->indexExists()) {
            return;
        }

        $this->forge->addKey('user_id', false, false, self::INDEX_NAME);
        $this->forge->processIndexes('users_api_access_keys');
    }

    public function down(): void
    {
        if (! $this->indexExists()) {
            return;
        }

        $this->forge->dropKey('users_api_access_keys', self::INDEX_NAME);
    }

    private function indexExists(): bool
    {
        // Migration::$db is typed as the generic ConnectionInterface, which
        // doesn't declare getIndexData() — go through Database::connect()
        // (typed @return BaseConnection) for the same connection instead.
        foreach (Database::connect($this->DBGroup)->getIndexData('users_api_access_keys') as $index) {
            if ($index->name === self::INDEX_NAME) {
                return true;
            }
        }

        return false;
    }
}
