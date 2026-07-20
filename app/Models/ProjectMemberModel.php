<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Over the `project_members` join table — grants a `users` row a role
 * (owner/admin/member/viewer) inside a `projects` row. Backs
 * `access_helper.php::project_role()`. Same shape as `WorkspaceMemberModel`.
 */
class ProjectMemberModel extends Model
{
    protected $table            = 'project_members';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'object';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = ['project_id', 'user_id', 'role'];
    protected $useTimestamps    = true;
    protected $createdField     = 'created_at';
    protected $updatedField     = '';

    /**
     * The caller's role in a project, or null if not a direct member.
     */
    public function roleFor(int $projectId, int $userId): ?string
    {
        $row = $this->where('project_id', $projectId)->where('user_id', $userId)->first();

        return $row->role ?? null;
    }

    /**
     * Members of a project, joined with `users` for display fields.
     *
     * @return list<object>
     */
    public function membersOf(int $projectId): array
    {
        return $this->select('users.id, users.name, users.email, project_members.role')
            ->join('users', 'users.id = project_members.user_id')
            ->where('project_members.project_id', $projectId)
            ->orderBy('users.name', 'ASC')
            ->findAll();
    }

    /**
     * Adds a user to a project with the given role, silently ignoring the
     * call if already a member (the `UNIQUE(project_id, user_id)` key backs
     * this).
     */
    public function add(int $projectId, int $userId, string $role = 'member'): void
    {
        if ($this->where('project_id', $projectId)->where('user_id', $userId)->first() !== null) {
            return;
        }

        $this->insert(['project_id' => $projectId, 'user_id' => $userId, 'role' => $role]);
    }

    public function remove(int $projectId, int $userId): void
    {
        $this->where('project_id', $projectId)->where('user_id', $userId)->delete();
    }
}
