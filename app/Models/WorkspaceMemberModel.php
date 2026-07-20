<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Over the `workspace_members` join table — grants a `users` row a role
 * (owner/admin/member/viewer) inside a `workspaces` row. Backs
 * `access_helper.php::workspace_role()`. Only `created_at` on this table.
 */
class WorkspaceMemberModel extends Model
{
    protected $table            = 'workspace_members';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'object';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = ['workspace_id', 'user_id', 'role'];
    protected $useTimestamps    = true;
    protected $createdField     = 'created_at';
    protected $updatedField     = '';

    /**
     * The caller's role in a workspace, or null if not a member.
     */
    public function roleFor(int $workspaceId, int $userId): ?string
    {
        $row = $this->where('workspace_id', $workspaceId)->where('user_id', $userId)->first();

        return $row->role ?? null;
    }

    /**
     * Members of a workspace, joined with `users` for display fields.
     *
     * @return list<object>
     */
    public function membersOf(int $workspaceId): array
    {
        return $this->select('users.id, users.name, users.email, workspace_members.role')
            ->join('users', 'users.id = workspace_members.user_id')
            ->where('workspace_members.workspace_id', $workspaceId)
            ->orderBy('users.name', 'ASC')
            ->findAll();
    }

    /**
     * Adds a user to a workspace with the given role, silently ignoring the
     * call if already a member (the `UNIQUE(workspace_id, user_id)` key
     * backs this).
     */
    public function add(int $workspaceId, int $userId, string $role = 'member'): void
    {
        if ($this->where('workspace_id', $workspaceId)->where('user_id', $userId)->first() !== null) {
            return;
        }

        $this->insert(['workspace_id' => $workspaceId, 'user_id' => $userId, 'role' => $role]);
    }

    public function remove(int $workspaceId, int $userId): void
    {
        $this->where('workspace_id', $workspaceId)->where('user_id', $userId)->delete();
    }
}
