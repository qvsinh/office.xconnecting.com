<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Over the `user_groups` table — a named, admin-managed set of users that
 * can be bulk-added to a workspace/project (see
 * `kanban/processing/20260721-00-master-social-hub.md`). Table is named
 * `user_groups`, not `groups` (reserved word in MySQL 8).
 */
class GroupModel extends Model
{
    protected $table            = 'user_groups';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'object';
    protected $useSoftDeletes   = true;
    protected $protectFields    = true;
    protected $allowedFields    = ['name', 'slug', 'description'];
    protected $useTimestamps    = true;
    protected $createdField     = 'created_at';
    protected $updatedField     = 'updated_at';
    protected $deletedField     = 'deleted_at';

    /**
     * Typed wrapper over `find()` (whose generic signature phpstan can't
     * narrow to `object` from `$returnType`) — mirrors `UserModel::findUser()`.
     */
    public function findGroup(int $id): ?object
    {
        return $this->find($id);
    }

    /**
     * Creates a group, deriving a unique `slug` from `$name`
     * (`url_title($name, '-', true)`, de-duplicated with a numeric suffix).
     * The one place `user_groups` rows are inserted so every row always gets
     * a slug.
     *
     * @return int inserted id
     */
    public function createGroup(string $name, ?string $description = null): int
    {
        return (int) $this->insert([
            'name'        => $name,
            'slug'        => $this->uniqueSlug($name),
            'description' => $description,
        ]);
    }

    /**
     * @return list<object>
     */
    public function groupsForUser(int $userId): array
    {
        return $this->select('user_groups.*')
            ->join('group_members', 'group_members.group_id = user_groups.id')
            ->where('group_members.user_id', $userId)
            ->orderBy('user_groups.name', 'ASC')
            ->findAll();
    }

    /**
     * Appends `-2`, `-3`, ... to the base slug until it's not already taken
     * — the one place slug uniqueness is enforced for `user_groups`.
     */
    private function uniqueSlug(string $name): string
    {
        helper('url');

        $base = url_title($name, '-', true);
        $slug = $base;
        $i    = 2;

        while ($this->where('slug', $slug)->first() !== null) {
            $slug = $base . '-' . $i++;
        }

        return $slug;
    }
}
