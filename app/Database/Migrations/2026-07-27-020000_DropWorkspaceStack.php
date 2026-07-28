<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use Config\Database;

/**
 * Drops the workspaces / projects / tasks module.
 *
 * This app is an office attendance system; the collaboration stack it inherited
 * from the starter was never used here, so the controllers, models, views, routes
 * and the eight `Create*` migrations were removed with it.
 *
 * A drop migration rather than ad-hoc SQL so any environment (dev, staging, a
 * colleague's checkout) converges on the same schema by running `spark migrate`.
 * On a fresh install the tables were never created, so every drop is a guarded
 * no-op.
 *
 * Order matters: children before parents, in case a deployment ever added the
 * foreign keys the original migrations left out.
 */
class DropWorkspaceStack extends Migration
{
    /**
     * Child-first, so dropping never trips over a dependent row.
     */
    private const TABLES = [
        'task_comments',
        'tasks',
        'task_columns',
        'project_files',
        'project_members',
        'projects',
        'workspace_members',
        'workspaces',
    ];

    public function up(): void
    {
        // See CreateUsers::up() for why resetDataCache() is required before
        // schema introspection on a long-lived connection.
        $db = Database::connect($this->DBGroup);
        $db->resetDataCache();

        foreach (self::TABLES as $table) {
            // `true` = IF EXISTS: a fresh install never had these.
            $this->forge->dropTable($table, true);
        }
    }

    public function down(): void
    {
        // Deliberately irreversible. The eight `Create*` migrations that defined
        // these tables were deleted along with the module, so there is nothing to
        // recreate them from — reviving the feature means restoring it from git
        // history, not rolling this back.
    }
}
