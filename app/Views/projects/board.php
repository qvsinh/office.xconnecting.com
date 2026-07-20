<?php
/**
 * Kanban board tab content — included from `projects/show.php` inside its
 * "Board" panel (`x-show="tab === 'board'"`). Partial only: no
 * `$this->extend()`/`section()`. Defines Alpine component
 * `projectBoard(projectId, canEdit)`; lazily fetches
 * GET /projects/{projectId}/board-data the first time the Board tab is
 * opened (via a $watch on the parent `projectShow()` component's `tab`,
 * which this nested x-data can read through Alpine's scope inheritance).
 *
 * @var object $project id — read for the board's projectId
 * @var bool   $canEdit read for edit/drag/delete affordances
 */
?>
<div x-data="projectBoard(<?= (int) $project->id ?>, <?= $canEdit ? 'true' : 'false' ?>)"
     x-init="$watch(() => tab, (v) => { if (v === 'board' && !loaded) load(); }); if (tab === 'board' && !loaded) load();">

    <template x-if="loading">
        <p class="py-10 text-center text-sm text-[#787774] dark:text-gray-400">Loading board…</p>
    </template>

    <template x-if="!loading && loaded">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:overflow-x-auto sm:pb-2">
            <template x-for="column in columns" :key="column.id">
                <div class="flex w-full flex-col rounded-lg border border-[#EAEAEA] bg-white sm:w-72 sm:flex-shrink-0 dark:border-gray-700 dark:bg-gray-800"
                     :class="dragOverColumnId === column.id ? 'ring-2 ring-brand-500' : ''"
                     @dragover.prevent="dragOverColumnId = column.id" @dragleave="dragOverColumnId = null"
                     @drop.prevent="onColumnDrop($event, column)">

                    <!-- Column header -->
                    <div class="flex items-center justify-between gap-2 border-b border-[#EAEAEA] px-3 py-2.5 dark:border-gray-700">
                        <template x-if="renamingColumnId !== column.id">
                            <button type="button" class="min-w-0 flex-1 truncate text-left text-sm font-semibold text-gray-800 dark:text-white/90"
                                    :class="canEdit ? 'hover:underline' : 'cursor-default'"
                                    @click="canEdit && startRename(column)">
                                <span x-text="column.name"></span>
                                <span class="ml-1 rounded-full bg-[#F7F6F3] px-1.5 py-0.5 text-xs font-medium text-[#787774] dark:bg-gray-700 dark:text-gray-300" x-text="column.tasks.length"></span>
                            </button>
                        </template>
                        <template x-if="renamingColumnId === column.id">
                            <input type="text" x-model="renameValue" class="form-input flex-1 py-1 text-sm"
                                   @keydown.enter.prevent="submitRename(column)" @keydown.escape.prevent="renamingColumnId = null"
                                   @blur="submitRename(column)" x-ref="renameInput">
                        </template>
                        <template x-if="canEdit && renamingColumnId !== column.id">
                            <button type="button" class="shrink-0 rounded-md p-1 text-[#787774] hover:bg-gray-100 hover:text-[#9F2F2D] dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-red-400"
                                    @click="confirmDeleteColumn(column)" aria-label="Delete column">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 7h12M9 7V5a1 1 0 011-1h4a1 1 0 011 1v2m2 0l-.867 12.142A2 2 0 0115.138 21H8.862a2 2 0 01-1.995-1.858L6 7" />
                                </svg>
                            </button>
                        </template>
                    </div>

                    <!-- Task cards -->
                    <div class="flex min-h-16 flex-1 flex-col gap-2 p-2">
                        <template x-if="column.tasks.length === 0">
                            <p class="px-2 py-4 text-center text-xs text-[#787774] dark:text-gray-400">No tasks.</p>
                        </template>
                        <template x-for="(task, idx) in column.tasks" :key="task.id">
                            <div :draggable="canEdit" x-data="{ moveOpen: false }"
                                 class="group relative cursor-pointer rounded-lg border border-[#EAEAEA] bg-white p-3 shadow-sm hover:border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:hover:border-gray-600"
                                 @dragstart="dragStart($event, task)" @dragend="draggedTask = null"
                                 @dragover.prevent.stop="dragOverColumnId = column.id" @drop.prevent.stop="onCardDrop($event, column, idx)"
                                 @click="openTask(task)">
                                <p class="pr-5 text-sm font-medium text-gray-800 dark:text-white/90" x-text="task.title"></p>
                                <div class="mt-2 flex flex-wrap items-center gap-2">
                                    <span class="rounded-full px-2 py-0.5 text-xs font-medium capitalize" :class="priorityClasses(task.priority)" x-text="task.priority"></span>
                                    <span x-show="task.assignee_name" class="flex items-center gap-1 text-xs text-[#787774] dark:text-gray-400">
                                        <span class="flex h-5 w-5 items-center justify-center rounded-full bg-brand-100 text-[10px] font-semibold text-brand-700 dark:bg-brand-900/40 dark:text-brand-300" x-text="initials(task.assignee_name)"></span>
                                        <span x-text="task.assignee_name"></span>
                                    </span>
                                    <span x-show="task.due_date" class="text-xs" :class="isOverdue(task) ? 'font-medium text-red-600 dark:text-red-400' : 'text-[#787774] dark:text-gray-400'" x-text="task.due_date"></span>
                                </div>

                                <!-- Mobile/touch move fallback: HTML5 drag is unreliable on touch -->
                                <template x-if="canEdit">
                                    <div class="absolute right-2 top-2" @click.stop>
                                        <button type="button" class="rounded p-0.5 text-[#787774] opacity-0 group-hover:opacity-100 hover:bg-gray-100 focus:opacity-100 dark:text-gray-400 dark:hover:bg-gray-700"
                                                :class="moveOpen ? 'opacity-100' : ''" @click="moveOpen = !moveOpen" aria-label="Move task">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 9l6 6 6-6" />
                                            </svg>
                                        </button>
                                        <div x-show="moveOpen" x-cloak @click.outside="moveOpen = false"
                                             class="absolute right-0 z-10 mt-1 w-40 rounded-md border border-[#EAEAEA] bg-white py-1 text-sm shadow-lg dark:border-gray-700 dark:bg-gray-800">
                                            <template x-for="target in columns.filter(c => c.id !== column.id)" :key="target.id">
                                                <button type="button" class="block w-full px-3 py-1.5 text-left text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-700"
                                                        @click="moveOpen = false; moveTaskToColumn(task, target)">
                                                    Move to <span x-text="target.name"></span>
                                                </button>
                                            </template>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </template>
                    </div>

                    <!-- Add task -->
                    <template x-if="canEdit">
                        <div class="flex items-center gap-1 border-t border-[#EAEAEA] p-2 dark:border-gray-700">
                            <input type="text" x-model="newTaskTitle[column.id]" placeholder="+ Add task" class="form-input py-1.5 text-sm"
                                   @keydown.enter.prevent="addTask(column)">
                            <button type="button" class="btn-secondary shrink-0 px-2.5 py-1.5 text-sm" @click="addTask(column)" :disabled="!(newTaskTitle[column.id] || '').trim()">Add</button>
                        </div>
                    </template>
                </div>
            </template>

            <!-- Add column -->
            <template x-if="canEdit">
                <div class="w-full rounded-lg border border-dashed border-[#EAEAEA] p-2 sm:w-56 sm:flex-shrink-0 dark:border-gray-700">
                    <template x-if="!addingColumnOpen">
                        <button type="button" class="w-full rounded-md px-3 py-2 text-left text-sm font-medium text-[#787774] hover:bg-gray-50 dark:text-gray-400 dark:hover:bg-gray-700"
                                @click="addingColumnOpen = true; $nextTick(() => $refs.newColumnInput?.focus())">
                            + Add column
                        </button>
                    </template>
                    <template x-if="addingColumnOpen">
                        <div class="flex items-center gap-1">
                            <input type="text" x-model="newColumnName" x-ref="newColumnInput" placeholder="Column name" class="form-input py-1.5 text-sm"
                                   @keydown.enter.prevent="addColumn()" @keydown.escape.prevent="addingColumnOpen = false; newColumnName = ''">
                            <button type="button" class="btn-primary shrink-0 px-2.5 py-1.5 text-sm" :disabled="addingColumn || !newColumnName.trim()" @click="addColumn()">Add</button>
                        </div>
                    </template>
                </div>
            </template>
        </div>
    </template>

    <!-- Task detail modal -->
    <div x-show="taskModalOpen" x-cloak class="fixed inset-0 z-40 flex items-center justify-center px-4" @keydown.escape.window="taskModalOpen = false">
        <div class="fixed inset-0 bg-black/40" @click="taskModalOpen = false"></div>
        <template x-if="selectedTask">
            <div x-show="taskModalOpen" x-transition class="modal-panel relative max-w-md" x-trap.noscroll="taskModalOpen" role="dialog" aria-modal="true" aria-labelledby="task-modal-title">
                <button type="button" class="absolute right-4 top-4 rounded-md p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:text-gray-500 dark:hover:bg-gray-800 dark:hover:text-gray-300"
                        @click="taskModalOpen = false" aria-label="Close">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
                <h2 id="task-modal-title" class="pr-8 text-lg font-semibold text-gray-800 dark:text-white/90">Task details</h2>
                <form @submit.prevent="saveTask" class="mt-4 space-y-4">
                    <div>
                        <label for="task_title" class="form-label">Title</label>
                        <input id="task_title" type="text" x-model="selectedTask.title" required maxlength="150" :disabled="!canEdit" class="form-input">
                    </div>
                    <div>
                        <label for="task_description" class="form-label">Description</label>
                        <textarea id="task_description" x-model="selectedTask.description" rows="3" :disabled="!canEdit" class="form-textarea"></textarea>
                    </div>
                    <div class="flex gap-4">
                        <div class="flex-1">
                            <label for="task_priority" class="form-label">Priority</label>
                            <select id="task_priority" x-model="selectedTask.priority" :disabled="!canEdit" class="form-select">
                                <option value="low">Low</option>
                                <option value="normal">Normal</option>
                                <option value="high">High</option>
                                <option value="urgent">Urgent</option>
                            </select>
                        </div>
                        <div class="flex-1">
                            <label for="task_due_date" class="form-label">Due date</label>
                            <input id="task_due_date" type="date" x-model="selectedTask.due_date" :disabled="!canEdit" class="form-input">
                        </div>
                    </div>
                    <div>
                        <label for="task_assignee" class="form-label">Assignee</label>
                        <select id="task_assignee" x-model="selectedTask.assignee_id" :disabled="!canEdit" class="form-select">
                            <option value="">Unassigned</option>
                            <template x-for="member in members" :key="member.id">
                                <option :value="member.id" x-text="member.name"></option>
                            </template>
                        </select>
                    </div>
                    <div class="flex items-center justify-between gap-3 pt-2">
                        <template x-if="canEdit">
                            <button type="button" class="text-sm font-medium text-[#9F2F2D] hover:underline dark:text-red-400" @click="confirmDeleteTask">Delete</button>
                        </template>
                        <div class="flex flex-1 justify-end gap-3">
                            <button type="button" class="btn-secondary" @click="taskModalOpen = false">Close</button>
                            <template x-if="canEdit">
                                <button type="submit" :disabled="savingTask" class="btn-primary">
                                    <span x-show="!savingTask">Save</span>
                                    <span x-show="savingTask" x-cloak>Saving…</span>
                                </button>
                            </template>
                        </div>
                    </div>
                </form>
            </div>
        </template>
    </div>

    <script>
        function projectBoard(projectId, canEdit) {
            return {
                projectId: projectId,
                canEdit: canEdit,
                loading: false,
                loaded: false,
                columns: [],
                members: [],
                newTaskTitle: {},

                addingColumnOpen: false,
                addingColumn: false,
                newColumnName: '',

                renamingColumnId: null,
                renameValue: '',

                draggedTask: null,
                dragOverColumnId: null,

                selectedTask: null,
                taskModalOpen: false,
                savingTask: false,

                async load() {
                    this.loading = true;
                    let res, json;
                    try {
                        res = await fetch('/projects/' + this.projectId + '/board-data', { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                        json = await res.json();
                    } catch (e) {
                        this.loading = false;
                        this.$dispatch('toast', { type: 'error', text: 'Network error. Please try again.' });
                        return;
                    }
                    this.loading = false;
                    if (json.status !== 'success') {
                        this.$dispatch('toast', { type: 'error', text: json.message || 'Could not load the board.' });
                        return;
                    }
                    this.columns = json.columns || [];
                    this.members = json.members || [];
                    this.columns.forEach(c => { if (!(c.id in this.newTaskTitle)) this.newTaskTitle[c.id] = ''; });
                    this.loaded = true;
                },

                priorityClasses(priority) {
                    return {
                        low: 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300',
                        high: 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300',
                        urgent: 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300',
                    }[priority] || 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300'; // normal
                },

                initials(name) {
                    return (name || '').trim().split(/\s+/).slice(0, 2).map(w => w[0].toUpperCase()).join('');
                },

                isOverdue(task) {
                    return !!task.due_date && task.due_date < new Date().toISOString().slice(0, 10);
                },

                async addTask(column) {
                    const title = (this.newTaskTitle[column.id] || '').trim();
                    if (!title) return;
                    const json = await postForm('/projects/' + this.projectId + '/tasks/create', { title, column_id: column.id });
                    if (!json) return;
                    if (json.status === 'success') {
                        column.tasks.push(json.task);
                        this.newTaskTitle[column.id] = '';
                        this.$dispatch('toast', { type: 'success', text: json.message || 'Task added.' });
                    } else {
                        this.$dispatch('toast', { type: 'error', text: json.message || 'Could not add the task.' });
                    }
                },

                openTask(task) {
                    this.selectedTask = { ...task, assignee_id: task.assignee_id ?? '', due_date: task.due_date ?? '', description: task.description ?? '' };
                    this.taskModalOpen = true;
                },

                async saveTask() {
                    if (!this.canEdit || !this.selectedTask) return;
                    this.savingTask = true;
                    const json = await postForm('/tasks/update/' + this.selectedTask.id, {
                        title: this.selectedTask.title,
                        description: this.selectedTask.description || '',
                        priority: this.selectedTask.priority,
                        assignee_id: this.selectedTask.assignee_id || '',
                        due_date: this.selectedTask.due_date || '',
                    });
                    this.savingTask = false;
                    if (!json) return;
                    if (json.status === 'success') {
                        this.replaceTaskLocal(json.task);
                        this.taskModalOpen = false;
                        this.$dispatch('toast', { type: 'success', text: json.message || 'Task updated.' });
                    } else {
                        this.$dispatch('toast', { type: 'error', text: json.message || 'Could not update the task.' });
                    }
                },

                confirmDeleteTask() {
                    if (!this.selectedTask) return;
                    const task = this.selectedTask;
                    this.$dispatch('confirm', {
                        title: 'Delete task',
                        body: 'Delete "' + task.title + '"? This cannot be undone.',
                        variant: 'danger',
                        confirmLabel: 'Delete',
                        onConfirm: async () => {
                            const json = await postForm('/tasks/delete/' + task.id, {});
                            if (!json) return;
                            if (json.status === 'success') {
                                this.removeTaskLocal(task.id);
                                this.taskModalOpen = false;
                                this.$dispatch('toast', { type: 'success', text: json.message || 'Task deleted.' });
                            } else {
                                this.$dispatch('toast', { type: 'error', text: json.message || 'Could not delete the task.' });
                            }
                        },
                    });
                },

                // Replaces a task in-place using a server-returned task object
                // (create/update responses include one); removes it from any
                // other column first in case its column_id changed.
                replaceTaskLocal(task) {
                    this.columns.forEach(col => { col.tasks = col.tasks.filter(t => t.id !== task.id); });
                    const target = this.columns.find(c => c.id === task.column_id);
                    if (target) target.tasks.push(task);
                },

                removeTaskLocal(taskId) {
                    this.columns.forEach(col => { col.tasks = col.tasks.filter(t => t.id !== taskId); });
                },

                dragStart(event, task) {
                    if (!this.canEdit) return;
                    this.draggedTask = task;
                    event.dataTransfer.effectAllowed = 'move';
                    event.dataTransfer.setData('text/plain', String(task.id));
                },

                // Drop onto the column body (not a specific card) — append to the end.
                onColumnDrop(event, column) {
                    this.dragOverColumnId = null;
                    if (!this.draggedTask) return;
                    const remaining = column.tasks.filter(t => t.id !== this.draggedTask.id);
                    this.moveTask(this.draggedTask, column, remaining.length);
                },

                // Drop onto a specific card — insert the dragged task before it.
                onCardDrop(event, column, idx) {
                    this.dragOverColumnId = null;
                    if (!this.draggedTask) return;
                    const fromIdx = column.tasks.findIndex(t => t.id === this.draggedTask.id);
                    let position = fromIdx !== -1 && fromIdx < idx ? idx - 1 : idx;
                    const remaining = column.tasks.filter(t => t.id !== this.draggedTask.id);
                    position = Math.max(0, Math.min(position, remaining.length));
                    this.moveTask(this.draggedTask, column, position);
                },

                // Mobile/touch fallback: always appends to the end of the chosen column.
                moveTaskToColumn(task, column) {
                    const remaining = column.tasks.filter(t => t.id !== task.id);
                    this.moveTask(task, column, remaining.length);
                },

                async moveTask(task, column, position) {
                    const prevColumnId = task.column_id;
                    const prevPosition = task.position;
                    this.applyMoveLocal(task, column.id, position);
                    this.draggedTask = null;
                    const json = await postForm('/tasks/move/' + task.id, { column_id: column.id, position });
                    if (!json || json.status !== 'success') {
                        this.applyMoveLocal(task, prevColumnId, prevPosition);
                        this.$dispatch('toast', { type: 'error', text: (json && json.message) || 'Could not move the task.' });
                        return;
                    }
                    this.$dispatch('toast', { type: 'success', text: json.message || 'Task moved.' });
                },

                applyMoveLocal(task, columnId, position) {
                    this.columns.forEach(col => { col.tasks = col.tasks.filter(t => t.id !== task.id); });
                    task.column_id = columnId;
                    task.position = position;
                    const target = this.columns.find(c => c.id === columnId);
                    if (target) target.tasks.splice(Math.max(0, Math.min(position, target.tasks.length)), 0, task);
                },

                startRename(column) {
                    this.renamingColumnId = column.id;
                    this.renameValue = column.name;
                    this.$nextTick(() => this.$refs.renameInput?.focus());
                },

                async submitRename(column) {
                    if (this.renamingColumnId !== column.id) return;
                    const name = this.renameValue.trim();
                    this.renamingColumnId = null;
                    if (!name || name === column.name) return;
                    const previous = column.name;
                    column.name = name;
                    const json = await postForm('/columns/rename/' + column.id, { name });
                    if (!json) return;
                    if (json.status === 'success') {
                        this.$dispatch('toast', { type: 'success', text: json.message || 'Column renamed.' });
                    } else {
                        column.name = previous;
                        this.$dispatch('toast', { type: 'error', text: json.message || 'Could not rename the column.' });
                    }
                },

                confirmDeleteColumn(column) {
                    this.$dispatch('confirm', {
                        title: 'Delete column',
                        body: 'Delete "' + column.name + '"? Any tasks in it move to the first column.',
                        variant: 'danger',
                        confirmLabel: 'Delete',
                        onConfirm: async () => {
                            const json = await postForm('/columns/delete/' + column.id, {});
                            if (!json) return;
                            if (json.status === 'success') {
                                this.$dispatch('toast', { type: 'success', text: json.message || 'Column deleted.' });
                                await this.load(); // server may have relocated its tasks — refetch for consistency
                            } else {
                                this.$dispatch('toast', { type: 'error', text: json.message || 'Could not delete the column.' });
                            }
                        },
                    });
                },

                async addColumn() {
                    const name = this.newColumnName.trim();
                    if (!name) return;
                    this.addingColumn = true;
                    const json = await postForm('/projects/' + this.projectId + '/columns/create', { name });
                    this.addingColumn = false;
                    if (!json) return;
                    if (json.status === 'success') {
                        this.newColumnName = '';
                        this.addingColumnOpen = false;
                        this.$dispatch('toast', { type: 'success', text: json.message || 'Column added.' });
                        await this.load(); // create response has no column payload — refetch to get its id
                    } else {
                        this.$dispatch('toast', { type: 'error', text: json.message || 'Could not add the column.' });
                    }
                },
            };
        }
    </script>
</div>
