<?php
/**
 * Task "List" tab content — included from `projects/show.php` inside its
 * "List" panel (`x-show="tab === 'list'"`). Partial only: no
 * `$this->extend()`/`section()`. Defines Alpine component
 * `projectTaskList(projectId)` which lazily fetches the same
 * GET /projects/{projectId}/board-data the board uses, flattens every
 * column's tasks into one client-sortable table (Title, Status = column,
 * Priority, Assignee, Due date). Read-only view.
 *
 * @var object $project id — read for the projectId
 */
?>
<div x-data="projectTaskList(<?= (int) $project->id ?>)"
     x-init="$watch(() => tab, (v) => { if (v === 'list' && !loaded) load(); }); if (tab === 'list' && !loaded) load();">

    <template x-if="loading">
        <p class="py-10 text-center text-sm text-[#787774] dark:text-gray-400">Loading tasks…</p>
    </template>

    <template x-if="!loading && loaded">
        <div class="overflow-x-auto rounded-lg border border-[#EAEAEA] bg-white dark:border-gray-700 dark:bg-gray-800">
            <table class="min-w-full divide-y divide-[#EAEAEA] text-sm dark:divide-gray-700">
                <thead>
                    <tr class="text-left text-xs font-medium uppercase tracking-wide text-[#787774] dark:text-gray-400">
                        <th class="px-4 py-3"><button type="button" class="hover:text-[#111111] dark:hover:text-gray-100" @click="sortBy('title')">Title<span x-text="sortMark('title')"></span></button></th>
                        <th class="px-4 py-3"><button type="button" class="hover:text-[#111111] dark:hover:text-gray-100" @click="sortBy('column_name')">Status<span x-text="sortMark('column_name')"></span></button></th>
                        <th class="px-4 py-3"><button type="button" class="hover:text-[#111111] dark:hover:text-gray-100" @click="sortBy('priority')">Priority<span x-text="sortMark('priority')"></span></button></th>
                        <th class="px-4 py-3"><button type="button" class="hover:text-[#111111] dark:hover:text-gray-100" @click="sortBy('assignee_name')">Assignee<span x-text="sortMark('assignee_name')"></span></button></th>
                        <th class="px-4 py-3"><button type="button" class="hover:text-[#111111] dark:hover:text-gray-100" @click="sortBy('due_date')">Due<span x-text="sortMark('due_date')"></span></button></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#EAEAEA] dark:divide-gray-700">
                    <template x-for="task in sortedTasks" :key="task.id">
                        <tr>
                            <td class="px-4 py-3 font-medium text-gray-800 dark:text-white/90" x-text="task.title"></td>
                            <td class="px-4 py-3">
                                <span class="rounded-full bg-[#F7F6F3] px-2 py-0.5 text-xs font-medium text-[#787774] dark:bg-gray-700 dark:text-gray-300" x-text="task.column_name"></span>
                            </td>
                            <td class="px-4 py-3">
                                <span class="rounded-full px-2 py-0.5 text-xs font-medium capitalize" :class="priorityClasses(task.priority)" x-text="task.priority"></span>
                            </td>
                            <td class="px-4 py-3 text-[#787774] dark:text-gray-400" x-text="task.assignee_name || '—'"></td>
                            <td class="px-4 py-3 text-xs" :class="isOverdue(task) ? 'font-medium text-red-600 dark:text-red-400' : 'text-[#787774] dark:text-gray-400'" x-text="task.due_date || '—'"></td>
                        </tr>
                    </template>
                    <tr x-show="tasks.length === 0">
                        <td colspan="5" class="px-4 py-10 text-center text-sm text-[#787774] dark:text-gray-400">No tasks in this project yet.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </template>

    <script>
        function projectTaskList(projectId) {
            return {
                projectId: projectId,
                loading: false,
                loaded: false,
                tasks: [],
                sortKey: 'due_date',
                sortDir: 'asc',

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
                        this.$dispatch('toast', { type: 'error', text: json.message || 'Could not load tasks.' });
                        return;
                    }
                    const rows = [];
                    (json.columns || []).forEach(col => {
                        (col.tasks || []).forEach(t => rows.push({ ...t, column_name: col.name }));
                    });
                    this.tasks = rows;
                    this.loaded = true;
                },

                get sortedTasks() {
                    const dir = this.sortDir === 'asc' ? 1 : -1;
                    const key = this.sortKey;
                    const rank = { low: 0, normal: 1, high: 2, urgent: 3 };
                    return [...this.tasks].sort((a, b) => {
                        let av = a[key] ?? '';
                        let bv = b[key] ?? '';
                        if (key === 'priority') { av = rank[a.priority] ?? 1; bv = rank[b.priority] ?? 1; }
                        if (av < bv) return -1 * dir;
                        if (av > bv) return 1 * dir;
                        return 0;
                    });
                },

                sortBy(key) {
                    if (this.sortKey === key) {
                        this.sortDir = this.sortDir === 'asc' ? 'desc' : 'asc';
                    } else {
                        this.sortKey = key;
                        this.sortDir = 'asc';
                    }
                },

                sortMark(key) {
                    if (this.sortKey !== key) return '';
                    return this.sortDir === 'asc' ? ' ▲' : ' ▼';
                },

                priorityClasses(priority) {
                    return {
                        low: 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300',
                        high: 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300',
                        urgent: 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300',
                    }[priority] || 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300'; // normal
                },

                isOverdue(task) {
                    return !!task.due_date && task.due_date < new Date().toISOString().slice(0, 10);
                },
            };
        }
    </script>
</div>
