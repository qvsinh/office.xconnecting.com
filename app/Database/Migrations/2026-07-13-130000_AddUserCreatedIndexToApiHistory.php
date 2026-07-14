<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use Config\Database;

/**
 * Every API history viewer query filters `WHERE user_id = ?` and sorts
 * `ORDER BY created_at DESC` by default (see `ApiHistoryModel::searchHistory()`).
 * `api_history` currently has no `user_id` index at all (only
 * `(service_name, service_id)`, `command`, `env` from the create migration),
 * so the per-user list would do a full scan. Leftmost-prefix on
 * `(user_id, created_at)` covers both the equality filter and the sort in one
 * index (mysql-patterns: composite indexes). Guarded against re-running on a
 * DB that already has it.
 */
class AddUserCreatedIndexToApiHistory extends Migration
{
    private const INDEX_NAME = 'idx_api_history_user_created';

    public function up(): void
    {
        if ($this->indexExists()) {
            return;
        }

        $this->forge->addKey(['user_id', 'created_at'], false, false, self::INDEX_NAME);
        $this->forge->processIndexes('api_history');
    }

    public function down(): void
    {
        if (! $this->indexExists()) {
            return;
        }

        $this->forge->dropKey('api_history', self::INDEX_NAME);
    }

    private function indexExists(): bool
    {
        // Migration::$db is typed as the generic ConnectionInterface, which
        // doesn't declare getIndexData() — go through Database::connect()
        // (typed @return BaseConnection) for the same connection instead.
        foreach (Database::connect($this->DBGroup)->getIndexData('api_history') as $index) {
            if ($index->name === self::INDEX_NAME) {
                return true;
            }
        }

        return false;
    }
}
