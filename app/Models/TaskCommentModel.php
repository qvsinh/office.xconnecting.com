<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Over the `task_comments` table — a flat comment thread per task. Only
 * `created_at` on this table (comments aren't edited).
 */
class TaskCommentModel extends Model
{
    private const SELECT_WITH_AUTHOR = 'task_comments.id, task_comments.task_id, task_comments.user_id, task_comments.body, task_comments.created_at, users.name AS author_name';

    protected $table            = 'task_comments';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'object';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = ['task_id', 'user_id', 'body'];
    protected $useTimestamps    = true;
    protected $createdField     = 'created_at';
    protected $updatedField     = '';

    /**
     * Typed wrapper over `find()` — mirrors `TaskModel::findTask()`.
     */
    public function findComment(int $id): ?object
    {
        return $this->find($id);
    }

    /**
     * All comments on a task, oldest first, joined with the author's name.
     *
     * @return list<object>
     */
    public function forTask(int $taskId): array
    {
        return $this->select(self::SELECT_WITH_AUTHOR)
            ->join('users', 'users.id = task_comments.user_id', 'left')
            ->where('task_comments.task_id', $taskId)
            ->orderBy('task_comments.created_at', 'ASC')
            ->orderBy('task_comments.id', 'ASC')
            ->findAll();
    }

    /**
     * One comment (with author name), for returning a freshly-created row.
     */
    public function findWithAuthor(int $id): ?object
    {
        return $this->select(self::SELECT_WITH_AUTHOR)
            ->join('users', 'users.id = task_comments.user_id', 'left')
            ->where('task_comments.id', $id)
            ->first();
    }
}
