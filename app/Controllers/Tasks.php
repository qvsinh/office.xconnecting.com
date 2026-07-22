<?php

namespace App\Controllers;

use App\Models\ProjectMemberModel;
use App\Models\ProjectModel;
use App\Models\TaskColumnModel;
use App\Models\TaskCommentModel;
use App\Models\TaskModel;
use App\Models\WorkspaceModel;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Task detail page + comment thread. Task field mutations (update/move/delete)
 * live in `Board`; this controller owns the standalone `/tasks/{id}` page and
 * its comments. Access is the same `project_role()`/`can_edit_project()` IDOR
 * guard used across the board — a task id alone never proves access.
 */
class Tasks extends BaseController
{
    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger): void
    {
        parent::initController($request, $response, $logger);
        helper(['auth', 'access']);
    }

    /**
     * Standalone task detail page. Redirects to /workspaces when the task (or
     * its project) is missing or the caller is not a project member.
     */
    public function show(int $id): ResponseInterface|string
    {
        $task = model(TaskModel::class)->findWithAssignee($id);

        if (! $task) {
            return redirect()->to('/workspaces');
        }

        $project = model(ProjectModel::class)->findProject((int) $task->project_id);

        if (! $project) {
            return redirect()->to('/workspaces');
        }

        if (project_role((int) $project->id) === null) {
            return redirect()->to('/workspaces');
        }

        $workspace = model(WorkspaceModel::class)->findWorkspace((int) $project->workspace_id);

        return view('tasks/show', [
            'task'          => $task,
            'project'       => $project,
            'workspace'     => $workspace,
            'members'       => model(ProjectMemberModel::class)->membersOf((int) $project->id),
            'columns'       => model(TaskColumnModel::class)->forProject((int) $project->id),
            'comments'      => model(TaskCommentModel::class)->forTask($id),
            'canEdit'       => can_edit_project((int) $project->id),
            'canManage'     => in_array(project_role((int) $project->id), ['owner', 'admin'], true),
            'currentUserId' => current_user_id(),
        ]);
    }

    /**
     * Add a comment. Any project member (read access) may comment.
     */
    public function addComment(int $id): ResponseInterface
    {
        $task = model(TaskModel::class)->findTask($id);

        if (! $task) {
            return $this->jsonFailed('Task not found.', 404);
        }

        if (project_role((int) $task->project_id) === null) {
            return $this->jsonFailed('You do not have access to this task.', 403);
        }

        if (! $this->validate(['body' => 'required|max_length[5000]'])) {
            return $this->jsonFailed($this->validator->getErrors());
        }

        $commentModel = model(TaskCommentModel::class);
        $commentId    = $commentModel->insert([
            'task_id' => $id,
            'user_id' => current_user_id(),
            'body'    => trim((string) $this->request->getPost('body')),
        ]);

        return $this->jsonSuccess('Comment added.', ['comment' => $commentModel->findWithAuthor((int) $commentId)]);
    }

    /**
     * Delete a comment. Allowed for its author or a project owner/admin.
     */
    public function deleteComment(int $commentId): ResponseInterface
    {
        $commentModel = model(TaskCommentModel::class);
        $comment      = $commentModel->findComment($commentId);

        if (! $comment) {
            return $this->jsonFailed('Comment not found.', 404);
        }

        $task = model(TaskModel::class)->findTask((int) $comment->task_id);

        if (! $task) {
            return $this->jsonFailed('Comment not found.', 404);
        }

        $isAuthor  = (int) $comment->user_id === current_user_id();
        $isManager = in_array(project_role((int) $task->project_id), ['owner', 'admin'], true);

        if (! $isAuthor && ! $isManager) {
            return $this->jsonFailed('You cannot delete this comment.', 403);
        }

        $commentModel->delete($commentId);

        return $this->jsonSuccess('Comment deleted.');
    }
}
