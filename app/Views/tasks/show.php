<?php
/**
 * Task detail page (`/tasks/{id}`): full task fields (editable when $canEdit,
 * reusing Board's /tasks/update + /tasks/delete endpoints) plus a comment
 * thread. Access is guarded in the controller (project membership).
 *
 * @var object       $task          id/title/description/priority/column_id/assignee_id/assignee_name/due_date/project_id
 * @var object       $project       id/name/workspace_id
 * @var object|null  $workspace     id/name
 * @var list<object> $members       id/name — project members (assignee options)
 * @var list<object> $columns       id/name — board columns (status options)
 * @var list<object> $comments      id/body/author_name/created_at/user_id
 * @var bool         $canEdit
 * @var bool         $canManage     owner/admin — may delete others' comments
 * @var int          $currentUserId
 */
$this->extend('layouts/app');

$priorityLabels = ['low' => 'Low', 'normal' => 'Normal', 'high' => 'High', 'urgent' => 'Urgent'];
?>

<?php $this->section('content') ?>
<div class="mx-auto max-w-3xl"
     x-data="taskDetail(<?= (int) $task->id ?>, <?= (int) $project->id ?>, <?= $canEdit ? 'true' : 'false' ?>, <?= $canManage ? 'true' : 'false' ?>, <?= (int) $currentUserId ?>)">

    <!-- Breadcrumb -->
    <nav class="flex flex-wrap items-center gap-1 text-sm text-[#787774] dark:text-gray-400">
        <a href="/workspaces" class="hover:text-[#111111] dark:hover:text-gray-100">Workspaces</a>
        <span>/</span>
        <?php if ($workspace !== null) : ?>
            <a href="/workspaces/<?= (int) $workspace->id ?>" class="hover:text-[#111111] dark:hover:text-gray-100"><?= esc($workspace->name ?? '') ?></a>
            <span>/</span>
        <?php endif ?>
        <a href="/projects/<?= (int) $project->id ?>" class="hover:text-[#111111] dark:hover:text-gray-100"><?= esc($project->name ?? '') ?></a>
        <span>/</span>
        <span class="text-[#111111] dark:text-gray-100">Task</span>
    </nav>

    <div class="mt-2 flex flex-wrap items-start justify-between gap-3">
        <h1 class="text-xl font-semibold tracking-tight" x-text="form.title"></h1>
        <?php if ($canEdit) : ?>
            <button type="button" class="btn-danger" @click="confirmDelete()">Delete task</button>
        <?php endif ?>
    </div>

    <!-- Task fields -->
    <div class="mt-6 rounded-lg border border-[#EAEAEA] bg-white p-5 dark:border-gray-700 dark:bg-gray-800">
        <form @submit.prevent="save" class="space-y-4">
            <div>
                <label for="t_title" class="form-label">Title</label>
                <input id="t_title" type="text" x-model="form.title" required maxlength="150" :disabled="!canEdit" class="form-input">
            </div>
            <div>
                <label for="t_description" class="form-label">Description</label>
                <textarea id="t_description" x-model="form.description" rows="5" :disabled="!canEdit" class="form-textarea"></textarea>
            </div>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label for="t_status" class="form-label">Status (column)</label>
                    <select id="t_status" x-model.number="form.column_id" :disabled="!canEdit" class="form-select">
                        <?php foreach ($columns as $c) : ?>
                            <option value="<?= (int) $c->id ?>"><?= esc($c->name ?? '') ?></option>
                        <?php endforeach ?>
                    </select>
                </div>
                <div>
                    <label for="t_priority" class="form-label">Priority</label>
                    <select id="t_priority" x-model="form.priority" :disabled="!canEdit" class="form-select">
                        <?php foreach ($priorityLabels as $value => $label) : ?>
                            <option value="<?= esc($value, 'attr') ?>"><?= esc($label) ?></option>
                        <?php endforeach ?>
                    </select>
                </div>
                <div>
                    <label for="t_assignee" class="form-label">Assignee</label>
                    <select id="t_assignee" x-model="form.assignee_id" :disabled="!canEdit" class="form-select">
                        <option value="">Unassigned</option>
                        <?php foreach ($members as $m) : ?>
                            <option value="<?= (int) $m->id ?>"><?= esc($m->name ?? '') ?></option>
                        <?php endforeach ?>
                    </select>
                </div>
                <div>
                    <label for="t_due" class="form-label">Due date</label>
                    <input id="t_due" type="date" x-model="form.due_date" :disabled="!canEdit" class="form-input">
                </div>
            </div>
            <?php if ($canEdit) : ?>
                <div class="flex justify-end gap-3 pt-1">
                    <a href="/projects/<?= (int) $project->id ?>" class="btn-secondary">Back to board</a>
                    <button type="submit" :disabled="saving" class="btn-primary">
                        <span x-show="!saving">Save changes</span>
                        <span x-show="saving" x-cloak>Saving…</span>
                    </button>
                </div>
            <?php endif ?>
        </form>
    </div>

    <!-- Comments -->
    <div class="mt-6">
        <h2 class="text-sm font-semibold text-gray-800 dark:text-white/90">
            Comments <span class="text-[#787774] dark:text-gray-400" x-text="'(' + comments.length + ')'"></span>
        </h2>

        <div class="mt-3 space-y-3">
            <template x-for="comment in comments" :key="comment.id">
                <div class="rounded-lg border border-[#EAEAEA] bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
                    <div class="flex items-center justify-between gap-3">
                        <div class="flex items-center gap-2">
                            <span class="flex h-6 w-6 items-center justify-center rounded-full bg-brand-100 text-[10px] font-semibold text-brand-700 dark:bg-brand-900/40 dark:text-brand-300" x-text="initials(comment.author_name)"></span>
                            <span class="text-sm font-medium text-gray-800 dark:text-white/90" x-text="comment.author_name || 'Unknown'"></span>
                            <span class="text-xs text-[#787774] dark:text-gray-400" x-text="comment.created_at"></span>
                        </div>
                        <button type="button" x-show="canManage || comment.user_id === currentUserId"
                                class="shrink-0 text-xs font-medium text-[#9F2F2D] hover:underline dark:text-red-400"
                                @click="confirmDeleteComment(comment)">Delete</button>
                    </div>
                    <p class="mt-2 whitespace-pre-wrap text-sm text-[#37352F] dark:text-gray-300" x-text="comment.body"></p>
                </div>
            </template>
            <p x-show="comments.length === 0" class="rounded-lg border border-dashed border-[#EAEAEA] px-4 py-8 text-center text-sm text-[#787774] dark:border-gray-700 dark:text-gray-400">
                No comments yet. Start the conversation below.
            </p>
        </div>

        <form @submit.prevent="addComment" class="mt-4">
            <label for="c_body" class="form-label">Add a comment</label>
            <textarea id="c_body" x-model="commentBody" rows="3" maxlength="5000" class="form-textarea" placeholder="Write a comment…"></textarea>
            <div class="mt-2 flex justify-end">
                <button type="submit" :disabled="posting || !commentBody.trim()" class="btn-primary">
                    <span x-show="!posting">Comment</span>
                    <span x-show="posting" x-cloak>Posting…</span>
                </button>
            </div>
        </form>
    </div>

    <script>
        function taskDetail(taskId, projectId, canEdit, canManage, currentUserId) {
            return {
                taskId: taskId,
                projectId: projectId,
                canEdit: canEdit,
                canManage: canManage,
                currentUserId: currentUserId,
                saving: false,
                posting: false,
                commentBody: '',
                form: {
                    title: <?= json_encode($task->title ?? '', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
                    description: <?= json_encode($task->description ?? '', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
                    priority: <?= json_encode($task->priority ?? 'normal', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
                    column_id: <?= (int) ($task->column_id ?? 0) ?>,
                    assignee_id: <?= json_encode((string) ($task->assignee_id ?? ''), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
                    due_date: <?= json_encode($task->due_date ?? '', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
                },
                comments: <?= json_encode(array_map(static fn ($c) => [
                    'id'          => (int) $c->id,
                    'body'        => $c->body ?? '',
                    'author_name' => $c->author_name ?? '',
                    'created_at'  => $c->created_at ?? '',
                    'user_id'     => (int) $c->user_id,
                ], $comments), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,

                initials(name) {
                    return (name || '').trim().split(/\s+/).slice(0, 2).map(w => w[0] ? w[0].toUpperCase() : '').join('') || '?';
                },

                async save() {
                    if (!this.canEdit) return;
                    this.saving = true;
                    const json = await postForm('/tasks/update/' + this.taskId, {
                        title: this.form.title,
                        description: this.form.description || '',
                        priority: this.form.priority,
                        column_id: this.form.column_id,
                        assignee_id: this.form.assignee_id || '',
                        due_date: this.form.due_date || '',
                    });
                    this.saving = false;
                    if (!json) return;
                    if (json.status === 'success') {
                        this.$dispatch('toast', { type: 'success', text: json.message || 'Task updated.' });
                    } else {
                        this.$dispatch('toast', { type: 'error', text: json.message || 'Could not update the task.' });
                    }
                },

                confirmDelete() {
                    this.$dispatch('confirm', {
                        title: 'Delete task',
                        body: 'Delete "' + this.form.title + '"? This cannot be undone.',
                        variant: 'danger',
                        confirmLabel: 'Delete',
                        onConfirm: async () => {
                            const json = await postForm('/tasks/delete/' + this.taskId, {});
                            if (!json) return;
                            if (json.status === 'success') {
                                window.location.href = '/projects/' + this.projectId;
                            } else {
                                this.$dispatch('toast', { type: 'error', text: json.message || 'Could not delete the task.' });
                            }
                        },
                    });
                },

                async addComment() {
                    const body = this.commentBody.trim();
                    if (!body) return;
                    this.posting = true;
                    const json = await postForm('/tasks/' + this.taskId + '/comments', { body });
                    this.posting = false;
                    if (!json) return;
                    if (json.status === 'success') {
                        this.comments.push(json.comment);
                        this.commentBody = '';
                        this.$dispatch('toast', { type: 'success', text: json.message || 'Comment added.' });
                    } else {
                        this.$dispatch('toast', { type: 'error', text: json.message || 'Could not add the comment.' });
                    }
                },

                confirmDeleteComment(comment) {
                    this.$dispatch('confirm', {
                        title: 'Delete comment',
                        body: 'Delete this comment? This cannot be undone.',
                        variant: 'danger',
                        confirmLabel: 'Delete',
                        onConfirm: async () => {
                            const json = await postForm('/comments/delete/' + comment.id, {});
                            if (!json) return;
                            if (json.status === 'success') {
                                this.comments = this.comments.filter(c => c.id !== comment.id);
                                this.$dispatch('toast', { type: 'success', text: json.message || 'Comment deleted.' });
                            } else {
                                this.$dispatch('toast', { type: 'error', text: json.message || 'Could not delete the comment.' });
                            }
                        },
                    });
                },
            };
        }
    </script>
</div>
<?php $this->endSection() ?>
