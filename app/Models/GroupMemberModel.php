<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Over the `group_members` join table — links a `user_groups` row to a
 * `users` row. Only `created_at` on this table (no `updated_at`) — mirrors
 * `SiteVariableModel`'s `updatedField=''` pattern for the same reason.
 */
class GroupMemberModel extends Model
{
    protected $table            = 'group_members';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'object';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = ['group_id', 'user_id'];
    protected $useTimestamps    = true;
    protected $createdField     = 'created_at';
    protected $updatedField     = '';

    /**
     * Members of a group, joined with `users` for display fields.
     *
     * @return list<object>
     */
    public function membersOf(int $groupId): array
    {
        return $this->select('users.id, users.name, users.email')
            ->join('users', 'users.id = group_members.user_id')
            ->where('group_members.group_id', $groupId)
            ->orderBy('users.name', 'ASC')
            ->findAll();
    }

    /**
     * Adds a user to a group, silently ignoring the call if already a
     * member (the `UNIQUE(group_id, user_id)` key backs this).
     */
    public function add(int $groupId, int $userId): void
    {
        if ($this->where('group_id', $groupId)->where('user_id', $userId)->first() !== null) {
            return;
        }

        $this->insert(['group_id' => $groupId, 'user_id' => $userId]);
    }

    public function remove(int $groupId, int $userId): void
    {
        $this->where('group_id', $groupId)->where('user_id', $userId)->delete();
    }
}
