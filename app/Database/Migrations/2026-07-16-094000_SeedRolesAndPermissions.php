<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;
use Config\Database;

/**
 * Seeds the two starter roles (`admin`, `user`) and a small starter
 * permission set, and grants every permission to `admin` (0 to `user`).
 * That part is a strict once-only guard (skipped entirely once the `admin`
 * role row exists). Separately, backfills any `users` row still missing a
 * `role_id` from its `zone` (admin/global -> admin role, else -> user role) — guarded by
 * `role_id IS NULL` instead, so it's safe to invoke again (e.g. a manual
 * data-fix after importing rows) without re-granting or duplicating roles.
 */
class SeedRolesAndPermissions extends Migration
{
    private const ROLES = [
        ['name' => 'admin', 'label' => 'Administrator'],
        ['name' => 'user', 'label' => 'User'],
    ];

    private const PERMISSIONS = [
        ['name' => 'users.manage', 'label' => 'Manage users'],
        ['name' => 'settings.manage', 'label' => 'Manage settings'],
        ['name' => 'system.manage', 'label' => 'Manage system'],
        ['name' => 'content.manage', 'label' => 'Manage content'],
    ];

    public function up(): void
    {
        $db = Database::connect($this->DBGroup);
        $db->resetDataCache();

        if (! $db->tableExists('roles') || ! $db->tableExists('permissions') || ! $db->tableExists('role_permissions')) {
            return;
        }

        $this->seedRolesAndGrants($db);
        $this->backfillUserRoleIds($db);
    }

    private function seedRolesAndGrants(BaseConnection $db): void
    {
        if ($db->table('roles')->where('name', 'admin')->countAllResults() > 0) {
            return;
        }

        $now = date('Y-m-d H:i:s');

        foreach (self::ROLES as $role) {
            $db->table('roles')->insert($role + ['created_at' => $now]);
        }
        $adminRoleId = (int) $db->table('roles')->where('name', 'admin')->get()->getRow()->id;

        foreach (self::PERMISSIONS as $permission) {
            $db->table('permissions')->insert($permission + ['created_at' => $now]);
        }
        $permissionIds = array_column($db->table('permissions')->get()->getResultArray(), 'id');

        $grants = [];
        foreach ($permissionIds as $permissionId) {
            $grants[] = ['role_id' => $adminRoleId, 'permission_id' => (int) $permissionId];
        }
        $db->table('role_permissions')->insertBatch($grants);
    }

    private function backfillUserRoleIds(BaseConnection $db): void
    {
        if (! $db->fieldExists('role_id', 'users')) {
            return;
        }

        $adminRole = $db->table('roles')->where('name', 'admin')->get()->getRow();
        $userRole  = $db->table('roles')->where('name', 'user')->get()->getRow();

        if ($adminRole === null || $userRole === null) {
            return;
        }

        // Accepts both zone vocabularies: `admin` (current) and `global` (before
        // migration 2026-07-27-010000 renamed the values). This backfill is
        // guarded by `role_id IS NULL` and documented as safe to re-run for a
        // manual data-fix, so it has to stay correct for whichever value the rows
        // actually hold — matching only the legacy name would silently give every
        // administrator the `user` role.
        $adminZones = ['admin', 'global'];

        $db->table('users')->whereIn('zone', $adminZones)->where('role_id', null)->update(['role_id' => (int) $adminRole->id]);
        $db->table('users')->whereNotIn('zone', $adminZones)->where('role_id', null)->update(['role_id' => (int) $userRole->id]);
    }

    public function down(): void
    {
        // Safe-on-live guard: only ever mutate data when connected to the
        // dedicated `tests` DB group — see SeedSiteNameVariable::down().
        $testsDatabase = (string) (config(Database::class)->tests['database'] ?? '');

        if ($testsDatabase === '' || $this->db->getDatabase() !== $testsDatabase) {
            return;
        }

        // No role_permissions cleanup needed here: CreateRolePermissions::down()
        // (which regress() runs right after this one) drops that table
        // outright, taking every grant with it — including ones tests may
        // have added for custom roles via RoleModel::syncPermissions().
        $this->db->table('roles')->whereIn('name', array_column(self::ROLES, 'name'))->delete();
        $this->db->table('permissions')->whereIn('name', array_column(self::PERMISSIONS, 'name'))->delete();
    }
}
