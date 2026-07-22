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
 * Adding a task and adding/configuring a column are both done through
 * modals (the "+ Add task" / "+ Add column" buttons and a column's settings
 * button open them) rather than inline inputs.
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
        <div>
            <!-- Board toolbar (top): add task / manage columns -->
            <template x-if="canEdit">
                <div class="mb-4 flex flex-wrap items-center gap-2">
                    <button type="button" class="btn-primary" @click="openTaskCreate()">+ Add task</button>
                    <button type="button" class="btn-secondary" @click="openManageColumns()">Manage columns</button>
                </div>
            </template>

            <div class="flex flex-col gap-5 sm:flex-row sm:items-start sm:overflow-x-auto sm:pb-2">
            <template x-for="column in columns" :key="column.id">
                <div class="flex min-h-[500px] w-full flex-col rounded-lg border border-[#EAEAEA] bg-white sm:w-72 sm:flex-shrink-0 dark:border-gray-700 dark:bg-gray-800"
                     :class="dragOverColumnId === column.id ? 'ring-2 ring-brand-500' : ''"
                     @dragover.prevent="dragOverColumnId = column.id" @dragleave="dragOverColumnId = null"
                     @drop.prevent="onColumnDrop($event, column)">

                    <!-- Column header (config lives in the Manage columns modal) -->
                    <div class="flex items-center justify-between gap-2 border-b border-[#EAEAEA] px-4 py-3 dark:border-gray-700">
                        <div class="flex min-w-0 items-center gap-1.5">
                            <span class="truncate text-sm font-semibold text-gray-800 dark:text-white/90" x-text="column.name"></span>
                            <span class="rounded-full bg-[#F7F6F3] px-1.5 py-0.5 text-xs font-medium text-[#787774] dark:bg-gray-700 dark:text-gray-300" x-text="column.tasks.length"></span>
                        </div>
                    </div>

                    <!-- Task cards -->
                    <div class="flex min-h-16 flex-1 flex-col gap-3 p-3">
                        <template x-if="column.tasks.length === 0">
                            <p class="px-2 py-4 text-center text-xs text-[#787774] dark:text-gray-400">No tasks.</p>
                        </template>
                        <template x-for="(task, idx) in column.tasks" :key="task.id">
                            <div :draggable="canEdit" x-data="{ moveOpen: false }"
                                 class="group relative cursor-pointer rounded-lg border border-[#EAEAEA] bg-white p-3 shadow-sm hover:border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:hover:border-gray-600"
                                 @dragstart="dragStart($event, task)" @dragend="draggedTask = null"
                                 @dragover.prevent.stop="dragOverColumnId = column.id" @drop.prevent.stop="onCardDrop($event, column, idx)"
                                 @click="window.location.href = '/tasks/' + task.id">
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
                </div>
            </template>
            </div>
        </div>
    </template>

    <!-- Manage columns modal: reorder (up/down), rename, delete, and add columns -->
    <div x-show="manageOpen" x-cloak class="fixed inset-0 z-40 flex items-center justify-center px-4" @keydown.escape.window="manageOpen = false">
        <div class="fixed inset-0 bg-black/40" @click="manageOpen = false"></div>
        <div x-show="manageOpen" x-transition class="modal-panel relative max-w-lg" x-trap.noscroll="manageOpen" role="dialog" aria-modal="true" aria-labelledby="manage-columns-title">
            <button type="button" class="absolute right-4 top-4 rounded-md p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:text-gray-500 dark:hover:bg-gray-800 dark:hover:text-gray-300"
                    @click="manageOpen = false" aria-label="Close">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
            <h2 id="manage-columns-title" class="pr-8 text-lg font-semibold text-gray-800 dark:text-white/90">Manage columns</h2>
            <p class="mt-1 text-sm text-[#787774] dark:text-gray-400">Reorder, rename, or remove the board's columns.</p>

            <div class="mt-4 space-y-2">
                <template x-for="(column, idx) in columns" :key="column.id">
                    <div class="flex items-center gap-2 rounded-lg border border-[#EAEAEA] p-2 dark:border-gray-700">
                        <div class="flex flex-col">
                            <button type="button" class="rounded p-0.5 text-[#787774] hover:bg-gray-100 hover:text-[#111111] disabled:cursor-not-allowed disabled:opacity-30 dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-gray-100"
                                    :disabled="idx === 0" @click="moveColumn(column, -1)" aria-label="Move up">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M18 15l-6-6-6 6" /></svg>
                            </button>
                            <button type="button" class="rounded p-0.5 text-[#787774] hover:bg-gray-100 hover:text-[#111111] disabled:cursor-not-allowed disabled:opacity-30 dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-gray-100"
                                    :disabled="idx === columns.length - 1" @click="moveColumn(column, 1)" aria-label="Move down">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M6 9l6 6 6-6" /></svg>
                            </button>
                        </div>
                        <input type="text" class="form-input flex-1 py-1.5 text-sm" maxlength="100"
                               x-model="column.name" @blur="renameColumn(column)" @keydown.enter.prevent="$event.target.blur()">
                        <span class="shrink-0 rounded-full bg-[#F7F6F3] px-2 py-0.5 text-xs font-medium text-[#787774] dark:bg-gray-700 dark:text-gray-300" x-text="column.tasks.length + ' task' + (column.tasks.length === 1 ? '' : 's')"></span>
                        <button type="button" class="shrink-0 rounded-md p-1 text-[#787774] hover:bg-gray-100 hover:text-[#9F2F2D] dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-red-400"
                                @click="confirmDeleteColumn(column)" aria-label="Delete column">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M6 7h12M9 7V5a1 1 0 011-1h4a1 1 0 011 1v2m2 0l-.867 12.142A2 2 0 0115.138 21H8.862a2 2 0 01-1.995-1.858L6 7" /></svg>
                        </button>
                    </div>
                </template>
            </div>

            <form @submit.prevent="addColumn" class="mt-4 flex items-center gap-2 border-t border-[#EAEAEA] pt-4 dark:border-gray-700">
                <input type="text" x-model="newColumnName" placeholder="New column name" maxlength="100" class="form-input flex-1 py-1.5 text-sm">
                <button type="submit" :disabled="addingColumn || !newColumnName.trim()" class="btn-primary shrink-0">
                    <span x-show="!addingColumn">Add</span>
                    <span x-show="addingColumn" x-cloak>Adding…</span>
                </button>
            </form>

            <div class="mt-4 flex justify-end">
                <button type="button" class="btn-secondary" @click="manageOpen = false">Done</button>
            </div>
        </div>
    </div>

    <!-- Create-task modal -->
    <div x-show="taskCreateOpen" x-cloak class="fixed inset-0 z-40 flex items-center justify-center px-4" @keydown.escape.window="taskCreateOpen = false">
        <div class="fixed inset-0 bg-black/40" @click="taskCreateOpen = false"></div>
        <div x-show="taskCreateOpen" x-transition class="modal-panel relative max-w-md" x-trap.noscroll="taskCreateOpen" role="dialog" aria-modal="true" aria-labelledby="task-create-title">
            <button type="button" class="absolute right-4 top-4 rounded-md p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:text-gray-500 dark:hover:bg-gray-800 dark:hover:text-gray-300"
                    @click="taskCreateOpen = false" aria-label="Close">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
            <h2 id="task-create-title" class="pr-8 text-lg font-semibold text-gray-800 dark:text-white/90">Add task</h2>
            <form @submit.prevent="submitTaskCreate" class="mt-4 space-y-4">
                <div>
                    <label for="new_task_title" class="form-label">Title</label>
                    <input id="new_task_title" type="text" x-model="taskCreateForm.title" x-ref="taskTitleInput" required maxlength="150" class="form-input">
                </div>
                <div>
                    <label for="new_task_description" class="form-label">Description</label>
                    <textarea id="new_task_description" x-model="taskCreateForm.description" rows="3" class="form-textarea"></textarea>
                </div>
                <div>
                    <label for="new_task_column" class="form-label">Column</label>
                    <select id="new_task_column" x-model.number="taskCreateForm.column_id" class="form-select">
                        <template x-for="c in columns" :key="c.id">
                            <option :value="c.id" x-text="c.name"></option>
                        </template>
                    </select>
                </div>
                <div class="flex gap-4">
                    <div class="flex-1">
                        <label for="new_task_priority" class="form-label">Priority</label>
                        <select id="new_task_priority" x-model="taskCreateForm.priority" class="form-select">
                            <option value="low">Low</option>
                            <option value="normal">Normal</option>
                            <option value="high">High</option>
                            <option value="urgent">Urgent</option>
                        </select>
                    </div>
                    <div class="flex-1">
                        <label for="new_task_due" class="form-label">Due date</label>
                        <input id="new_task_due" type="date" x-model="taskCreateForm.due_date" class="form-input">
                    </div>
                </div>
                <div>
                    <label for="new_task_assignee" class="form-label">Assignee</label>
                    <select id="new_task_assignee" x-model="taskCreateForm.assignee_id" class="form-select">
                        <option value="">Unassigned</option>
                        <template x-for="member in members" :key="member.id">
                            <option :value="member.id" x-text="member.name"></option>
                        </template>
                    </select>
                </div>
                <div class="flex justify-end gap-3 pt-2">
                    <button type="button" class="btn-secondary" @click="taskCreateOpen = false">Cancel</button>
                    <button type="submit" :disabled="creatingTask || !taskCreateForm.title.trim()" class="btn-primary">
                        <span x-show="!creatingTask">Add task</span>
                        <span x-show="creatingTask" x-cloak>Adding…</span>
                    </button>
                </div>
            </form>
        </div>
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

                manageOpen: false,
                addingColumn: false,
                newColumnName: '',

                taskCreateOpen: false,
                creatingTask: false,
                taskCreateForm: { title: '', column_id: null, priority: 'normal', assignee_id: '', due_date: '' },

                draggedTask: null,
                dragOverColumnId: null,

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
                    // Snapshot each column's name (_origName) so the Manage
                    // columns modal can revert an inline rename that fails.
                    this.columns = (json.columns || []).map(c => ({ ...c, _origName: c.name }));
                    this.members = json.members || [];
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

                // --- Add task (modal) ---
                openTaskCreate(column = null) {
                    const columnId = column ? column.id : (this.columns[0] ? this.columns[0].id : null);
                    this.taskCreateForm = { title: '', description: '', column_id: columnId, priority: 'normal', assignee_id: '', due_date: '' };
                    this.taskCreateOpen = true;
                    this.$nextTick(() => this.$refs.taskTitleInput?.focus());
                },

                async submitTaskCreate() {
                    const title = this.taskCreateForm.title.trim();
                    if (!title) return;
                    this.creatingTask = true;
                    const json = await postForm('/projects/' + this.projectId + '/tasks/create', {
                        title,
                        description: this.taskCreateForm.description || '',
                        column_id: this.taskCreateForm.column_id,
                        priority: this.taskCreateForm.priority,
                        assignee_id: this.taskCreateForm.assignee_id || '',
                        due_date: this.taskCreateForm.due_date || '',
                    });
                    this.creatingTask = false;
                    if (!json) return;
                    if (json.status === 'success') {
                        const target = this.columns.find(c => c.id === json.task.column_id);
                        if (target) target.tasks.push(json.task);
                        this.taskCreateOpen = false;
                        this.$dispatch('toast', { type: 'success', text: json.message || 'Task added.' });
                    } else {
                        this.$dispatch('toast', { type: 'error', text: json.message || 'Could not add the task.' });
                    }
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

                // --- Manage columns (modal): reorder / rename / delete / add ---
                openManageColumns() {
                    this.newColumnName = '';
                    this.manageOpen = true;
                },

                // Inline rename from the manage list; optimistic, reverts on failure.
                async renameColumn(column) {
                    const name = (column.name || '').trim();
                    if (name === '') { column.name = column._origName; return; }
                    if (name === column._origName) return;
                    const json = await postForm('/columns/rename/' + column.id, { name });
                    if (!json) return;
                    if (json.status === 'success') {
                        column.name = name;
                        column._origName = name;
                        this.$dispatch('toast', { type: 'success', text: json.message || 'Column renamed.' });
                    } else {
                        column.name = column._origName;
                        this.$dispatch('toast', { type: 'error', text: json.message || 'Could not rename the column.' });
                    }
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
                        this.$dispatch('toast', { type: 'success', text: json.message || 'Column added.' });
                        await this.load(); // refetch to get the new column's id + position
                    } else {
                        this.$dispatch('toast', { type: 'error', text: json.message || 'Could not add the column.' });
                    }
                },

                // Move a column one step earlier/later (dir = -1 | +1) and
                // persist the new left-to-right order. Optimistic; reverts on failure.
                async moveColumn(column, dir) {
                    const idx = this.columns.findIndex(c => c.id === column.id);
                    const target = idx + dir;
                    if (idx === -1 || target < 0 || target >= this.columns.length) return;

                    const original = this.columns.slice();
                    const arr = this.columns.slice();
                    [arr[idx], arr[target]] = [arr[target], arr[idx]];
                    this.columns = arr;

                    const order = this.columns.map(c => c.id).join(',');
                    const json = await postForm('/projects/' + this.projectId + '/columns/reorder', { order });
                    if (!json || json.status !== 'success') {
                        this.columns = original;
                        this.$dispatch('toast', { type: 'error', text: (json && json.message) || 'Could not reorder columns.' });
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
            };
        }
    </script>
</div>
