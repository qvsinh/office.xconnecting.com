<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use Config\Database;

/**
 * Adds the two attendance permissions and grants them to the `admin` role.
 *
 * Attendance management is deliberately permission-gated rather than
 * admin-zone-gated: a department head must be able to review and correct their
 * team's attendance without being handed global admin rights. `PermissionFilter`
 * already lets admin-zone users through regardless.
 *
 * `requests.approve` is seeded here (not in card 02) so both cards share one
 * migration and the grant can never be half-applied.
 *
 * Guarded per permission name, so it is re-runnable and safe on a live DB where
 * SeedRolesAndPermissions has long since run.
 */
class SeedAttendancePermissions extends Migration
{
    private const PERMISSIONS = [
        ['name' => 'attendance.manage', 'label' => 'Manage attendance'],
        ['name' => 'requests.approve', 'label' => 'Approve leave requests'],
    ];

    public function up(): void
    {
        $db = Database::connect($this->DBGroup);
        $db->resetDataCache();

        if (! $db->tableExists('roles') || ! $db->tableExists('permissions') || ! $db->tableExists('role_permissions')) {
            return;
        }

        $adminRole = $db->table('roles')->where('name', 'admin')->get()->getRow();

        if ($adminRole === null) {
            return;
        }

        $now = date('Y-m-d H:i:s');

        foreach (self::PERMISSIONS as $permission) {
            $existing = $db->table('permissions')->where('name', $permission['name'])->get()->getRow();

            if ($existing === null) {
                $db->table('permissions')->insert($permission + ['created_at' => $now]);
                $permissionId = (int) $db->insertID();
            } else {
                $permissionId = (int) $existing->id;
            }

            $granted = $db->table('role_permissions')
                ->where('role_id', (int) $adminRole->id)
                ->where('permission_id', $permissionId)
                ->countAllResults() > 0;

            if (! $granted) {
                $db->table('role_permissions')->insert([
                    'role_id'       => (int) $adminRole->id,
                    'permission_id' => $permissionId,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Safe-on-live guard: only ever mutate data when connected to the
        // dedicated `tests` DB group — see SeedRolesAndPermissions::down().
        $testsDatabase = (string) (config(Database::class)->tests['database'] ?? '');

        if ($testsDatabase === '' || $this->db->getDatabase() !== $testsDatabase) {
            return;
        }

        $names         = array_column(self::PERMISSIONS, 'name');
        $permissionIds = array_column(
            $this->db->table('permissions')->select('id')->whereIn('name', $names)->get()->getResultArray(),
            'id'
        );

        if ($permissionIds !== []) {
            $this->db->table('role_permissions')->whereIn('permission_id', $permissionIds)->delete();
        }

        $this->db->table('permissions')->whereIn('name', $names)->delete();
    }
}
